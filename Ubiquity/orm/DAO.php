<?php

namespace Ubiquity\orm;

use Ubiquity\db\Database;
use Ubiquity\log\Logger;
use Ubiquity\orm\parser\ManyToManyParser;
use Ubiquity\db\SqlUtils;
use Ubiquity\orm\parser\Reflexion;
use Ubiquity\orm\traits\DAOUpdatesTrait;

/**
 * Gateway class between database and object model
 * @author jc
 * @version 1.0.0.6
 * @package orm
 */
class DAO {
	use DAOUpdatesTrait;
	public static $db;

	/**
	 * Loads member associated with $instance by a ManyToOne type relationship
	 * @param object $instance
	 * @param mixed $value
	 * @param array $annotationArray
	 * @param boolean $useCache
	 */
	public static function getManyToOne($instance, $member, $useCache=NULL) {
		$fieldAnnot=OrmUtils::getMemberJoinColumns($instance, $member);
		if($fieldAnnot!==null){
			$field=$fieldAnnot[0];
			$value=Reflexion::getMemberValue($instance, $field);
			$annotationArray=$fieldAnnot[1];
			$member=$annotationArray["member"];
			$key=OrmUtils::getFirstKey($annotationArray["className"]);
			$kv=array ($key => $value );
			$obj=self::getOne($annotationArray["className"], $kv, false, false, $useCache);
			if ($obj !== null) {
				Logger::log("getManyToOne", "Chargement de " . $member . " pour l'objet " . \get_class($instance));
				$accesseur="set" . ucfirst($member);
				if (method_exists($instance, $accesseur)) {
					$instance->$accesseur($obj);
					$instance->_rest[$member]=$obj->_rest;
					return;
				}
			}
		}
	}

	private static function _getOneToManyFromArray(&$ret, $array, $fkv, $mappedBy) {
		$elementAccessor="get" . ucfirst($mappedBy);
		foreach ( $array as $element ) {
			$elementRef=$element->$elementAccessor();
			if (!is_null($elementRef)) {
				$idElementRef=OrmUtils::getFirstKeyValue($elementRef);
				if ($idElementRef == $fkv)
					$ret[]=$element;
			}
		}
	}

	/**
	 * Assign / load the child records in the $member member of $instance.
	 * @param object $instance
	 * @param string $member Member on which a oneToMany annotation must be present
	 * @param boolean $useCache
	 * @param array $annot used internally
	 */
	public static function getOneToMany($instance, $member, $useCache=NULL, $annot=null) {
		$ret=array ();
		$class=get_class($instance);
		if (!isset($annot))
			$annot=OrmUtils::getAnnotationInfoMember($class, "#oneToMany", $member);
			if ($annot !== false) {
				$fkAnnot=OrmUtils::getAnnotationInfoMember($annot["className"], "#joinColumn", $annot["mappedBy"]);
				if ($fkAnnot !== false) {
					$fkv=OrmUtils::getFirstKeyValue($instance);
					$ret=self::getAll($annot["className"], $fkAnnot["name"] . "='" . $fkv . "'", true, false, $useCache);
					self::setToMember($member, $instance, $ret, $class, "getOneToMany");
				}
			}
			return $ret;
	}

	/**
	 * @param object $instance
	 * @param string $member
	 * @param array $array
	 * @param string $mappedBy
	 */
	public static function affectsOneToManyFromArray($instance, $member, $array=null, $mappedBy=null) {
		$ret=array ();
		$class=get_class($instance);
		if (!isset($mappedBy)){
			$annot=OrmUtils::getAnnotationInfoMember($class, "#oneToMany", $member);
			$mappedBy=$annot["mappedBy"];
		}
		if ($mappedBy !== false) {
				$fkv=OrmUtils::getFirstKeyValue($instance);
				self::_getOneToManyFromArray($ret, $array, $fkv, $mappedBy);
				self::setToMember($member, $instance, $ret, $class, "getOneToMany");
		}
		return $ret;
	}

	private static function setToMember($member, $instance, $value, $class, $part) {
		$accessor="set" . ucfirst($member);
		if (method_exists($instance, $accessor)) {
			Logger::log($part, "Affectation de " . $member . " pour l'objet " . $class);
			$instance->$accessor($value);
			$instance->_rest[$member]=$value;
		} else {
			Logger::warn($part, "L'accesseur " . $accessor . " est manquant pour " . $class);
		}
	}

	/**
	 *
	 * @param object $instance
	 * @param ManyToManyParser $parser
	 * @return PDOStatement
	 */
	private static function getSQLForJoinTable($instance, ManyToManyParser $parser) {
		$accessor="get" . ucfirst($parser->getPk());
		$sql="SELECT * FROM `" . $parser->getJoinTable() . "` WHERE `" . $parser->getMyFkField() . "`='" . $instance->$accessor() . "'";
		Logger::log("ManyToMany", "Exécution de " . $sql);
		return self::$db->query($sql);
	}

	/**
	 * Affecte/charge les enregistrements fils dans le membre $member de $instance.
	 * Si $array est null, les fils sont chargés depuis la base de données
	 * @param object $instance
	 * @param string $member Membre sur lequel doit être présent une annotation ManyToMany
	 * @param array $array paramètre facultatif contenant la liste des fils possibles
	 * @param boolean $useCache
	 */
	public static function getManyToMany($instance, $member, $array=null, $useCache=NULL) {
		$ret=array ();
		$class=get_class($instance);
		$parser=new ManyToManyParser($instance, $member);
		if ($parser->init()) {
			if (is_null($array)) {
				$joinTableCursor=self::getSQLForJoinTable($instance, $parser);
				foreach ( $joinTableCursor as $row ) {
					$fkv=$row[$parser->getFkField()];
					$tmp=self::getOne($parser->getTargetEntity(), "`" . $parser->getPk() . "`='" . $fkv . "'", false, false, $useCache);
					array_push($ret, $tmp);
				}
			} else {
				self::getManyToManyFromArray($ret, $instance, $array, $class, $parser);
			}
			self::setToMember($member, $instance, $ret, $class, "getManyToMany");
		}
		return $ret;
	}

	private static function getManyToManyFromArray(&$ret, $instance, $array, $class, $parser) {
		$continue=true;
		$accessorToMember="get" . ucfirst($parser->getInversedBy());
		$myPkAccessor="get" . ucfirst($parser->getMyPk());

		if (!method_exists($instance, $myPkAccessor)) {
			Logger::warn("ManyToMany", "L'accesseur au membre clé primaire " . $myPkAccessor . " est manquant pour " . $class);
		}
		if (count($array) > 0)
			$continue=method_exists($array[0], $accessorToMember);
		if ($continue) {
			foreach ( $array as $targetEntityInstance ) {
				$instances=$targetEntityInstance->$accessorToMember();
				if (is_array($instances)) {
					foreach ( $instances as $inst ) {
						if ($inst->$myPkAccessor() == $instance->$myPkAccessor())
							array_push($ret, $targetEntityInstance);
					}
				}
			}
		} else {
			Logger::warn("ManyToMany", "L'accesseur au membre " . $parser->getInversedBy() . " est manquant pour " . $parser->getTargetEntity());
		}
	}

	/**
	 * Returns an array of $className objects from the database
	 * @param string $className class name of the model to load
	 * @param string $condition Part following the WHERE of an SQL statement
	 * @param boolean $loadManyToOne if true, charges associate members with manyToOne association
	 * @param boolean $loadOneToMany if true, charges associate members with oneToMany association
	 * @param boolean $useCache use the active cache if true
	 * @return array
	 */
	public static function getAll($className, $condition='', $loadManyToOne=true, $loadOneToMany=false, $useCache=NULL) {
		$objects=array ();
		$invertedJoinColumns=null;
		$oneToManyFields=null;
		$metaDatas=OrmUtils::getModelMetadata($className);
		$tableName=$metaDatas["#tableName"];
		if ($loadManyToOne && isset($metaDatas["#invertedJoinColumn"]))
			$invertedJoinColumns=$metaDatas["#invertedJoinColumn"];
		if ($loadOneToMany && isset($metaDatas["#oneToMany"])) {
			$oneToManyFields=$metaDatas["#oneToMany"];
		}
		if ($condition != ''){
			$condition=" WHERE " . $condition;
		}
		$members=\array_diff($metaDatas["#fieldNames"],$metaDatas["#notSerializable"]);
		$query=self::$db->prepareAndExecute($tableName, $condition,$members,$useCache);
		Logger::log("getAll", "SELECT * FROM " . $tableName . $condition);
		$oneToManyQueries=[];
		$manyToOneQueries=[];

		foreach ( $query as $row ) {
			$object=self::loadObjectFromRow($row, $className, $invertedJoinColumns, $oneToManyFields,$members, $oneToManyQueries,$manyToOneQueries,$useCache);
			$key=OrmUtils::getFirstKeyValue($object);
			$objects[$key]=$object;
		}

		if($loadManyToOne && \sizeof($manyToOneQueries)>0){
			self::_affectsObjectsFromArray($manyToOneQueries, $objects, function($object,$member,$manyToOneObjects,$fkField){
				self::affectsManyToOneFromArray($object,$member,$manyToOneObjects,$fkField);
			});
		}

		if($loadOneToMany && \sizeof($oneToManyQueries)>0){
			self::_affectsObjectsFromArray($oneToManyQueries, $objects, function($object,$member,$relationObjects,$fkField){
				self::affectsOneToManyFromArray($object,$member,$relationObjects,$fkField);
			});
		}
		return $objects;
	}

	private static function _affectsObjectsFromArray($queries,$objects,$affectsCallback,$useCache=NULL){
		foreach ($queries as $key=>$conditions){
			list($class,$member,$fkField)=\explode("|", $key);
			$condition=\implode(" OR ", $conditions);
			$relationObjects=self::getAll($class,$condition,true,false,$useCache);
			foreach ($objects as $object){
				$affectsCallback($object, $member,$relationObjects,$fkField);
			}
		}
	}

	private static function affectsManyToOneFromArray($object,$member,$manyToOneObjects,$fkField){
		$class=\get_class($object);
		if(isset($object->$fkField)){
			$value=$manyToOneObjects[$object->$fkField];
			self::setToMember($member, $object, $value, $class, "getManyToOne");
		}
	}

	/**
	 * @param array $row
	 * @param string $className
	 * @param array $invertedJoinColumns
	 * @param array $oneToManyFields
	 * @param array $members
	 * @param array $oneToManyQueries
	 * @param array $manyToOneQueries
	 * @param boolean $useCache
	 * @return object
	 */
	private static function loadObjectFromRow($row, $className, $invertedJoinColumns, $oneToManyFields, $members,&$oneToManyQueries,&$manyToOneQueries,$useCache=NULL) {
		$o=new $className();
		foreach ( $row as $k => $v ) {
			if(($field=\array_search($k, $members))!==false){
				$accesseur="set" . ucfirst($field);
				if (method_exists($o, $accesseur)) {
					$o->$accesseur($v);
					$o->_rest[$field]=$v;
				}
			}
			if (isset($invertedJoinColumns) && isset($invertedJoinColumns[$k])) {
				$fk="_".$k;
				$o->$fk=$v;
				self::prepareManyToOne($manyToOneQueries,$v, $fk,$invertedJoinColumns[$k]);
			}
		}
		if (isset($oneToManyFields)) {
			foreach ( $oneToManyFields as $k => $annot ) {
				self::prepareOneToMany($oneToManyQueries,$o, $k, $annot);
			}
		}
		return $o;
	}


	/**
	 * Prepares members associated with $instance with a oneToMany type relationship
	 * @param $ret array of sql conditions
	 * @param object $instance
	 * @param string $member Member on which a OneToMany annotation must be present
	 * @param array $annot used internally
	 */
	private static function prepareOneToMany(&$ret,$instance, $member, $annot=null) {
		$class=get_class($instance);
		if (!isset($annot))
			$annot=OrmUtils::getAnnotationInfoMember($class, "#oneToMany", $member);
			if ($annot !== false) {
				$fkAnnot=OrmUtils::getAnnotationInfoMember($annot["className"], "#joinColumn", $annot["mappedBy"]);
				if ($fkAnnot !== false) {
					$fkv=OrmUtils::getFirstKeyValue($instance);
					$key=$annot["className"]."|".$member."|".$annot["mappedBy"];
					if(!isset($ret[$key])){
						$ret[$key]=[];
					}
					$ret[$key][$fkv]=$fkAnnot["name"] . "='" . $fkv . "'";
				}
			}
	}

	/**
	 * Prepares members associated with $instance with a manyToOne type relationship
	 * @param $ret array of sql conditions
	 * @param mixed $value
	 * @param string $fkField
	 * @param array $annotationArray
	 */
	private static function prepareManyToOne(&$ret, $value, $fkField,$annotationArray) {
		$member=$annotationArray["member"];
		$fk=OrmUtils::getFirstKey($annotationArray["className"]);
		$key=$annotationArray["className"]."|".$member."|".$fkField;
		if(!isset($ret[$key])){
			$ret[$key]=[];
		}
		$ret[$key][$value]=$fk . "='" . $value . "'";
	}

	/**
	 * Returns the number of objects of $className from the database respecting the condition possibly passed as parameter
	 * @param string $className complete classname of the model to load
	 * @param string $condition Part following the WHERE of an SQL statement
	 * @return int count of objects
	 */
	public static function count($className, $condition='') {
		$tableName=OrmUtils::getTableName($className);
		if ($condition != '')
			$condition=" WHERE " . $condition;
		return self::$db->query("SELECT COUNT(*) FROM " . $tableName . $condition)->fetchColumn();
	}

	/**
	 * Returns an instance of $className from the database, from $keyvalues values of the primary key
	 * @param String $className complete classname of the model to load
	 * @param Array|string $keyValues primary key values or condition
	 * @param $loadManyToOne if true, charges associate members with manyToOne association
	 * @param $loadOneToMany if true, charges associate members with oneToMany association
	 * @param boolean $useCache use cache if true
	 * @return object the instance loaded or null if not found
	 */
	public static function getOne($className, $keyValues, $loadManyToOne=true, $loadOneToMany=false, $useCache=NULL) {
		if (!is_array($keyValues)) {
			if (strrpos($keyValues, "=") === false) {
				$keyValues="`" . OrmUtils::getFirstKey($className) . "`='" . $keyValues . "'";
			}
		}
		$condition=SqlUtils::getCondition($keyValues,$className);
		$retour=self::getAll($className, $condition." limit 1", $loadManyToOne, $loadOneToMany, $useCache);
		if (sizeof($retour) < 1){
			return null;
		}
		return \reset($retour);
	}

	/**
	 * Establishes the connection to the database using the past parameters
	 * @param string $dbType
	 * @param string $dbName
	 * @param string $serverName
	 * @param string $port
	 * @param string $user
	 * @param string $password
	 * @param array $options
	 * @param boolean $cache
	 */
	public static function connect($dbType,$dbName, $serverName="127.0.0.1", $port="3306", $user="root", $password="", $options=[],$cache=false) {
		self::$db=new Database($dbType,$dbName, $serverName, $port, $user, $password, $options,$cache);
		try {
			self::$db->connect();
		} catch (\Exception $e) {
			Logger::error("DAO", $e->getMessage());
			throw $e;
		}
	}

	/**
	 * Returns true if the connection to the database is estabished
	 * @return boolean
	 */
	public static function isConnected(){
		return self::$db!==null && (self::$db instanceof Database) && self::$db->isConnected();
	}
}