<?php
/* Copyright (c) 1998-2009 ILIAS open source, Extended GPL, see docs/LICENSE */

require_once("./Services/Database/classes/PDO/class.ilPDOStatement.php");
require_once("./Services/Database/classes/QueryUtils/class.ilMySQLQueryUtils.php");
require_once('./Services/Database/classes/PDO/Manager/class.ilDBPdoManager.php');
require_once('./Services/Database/classes/PDO/Reverse/class.ilDBPdoReverse.php');

/**
 * Class pdoDB
 *
 * @author Oskar Truffer <ot@studer-raimann.ch>
 * @author Fabian Schmid <fs@studer-raimann.ch>
 */
class ilDBPdo implements ilDBInterface {

	const FEATURE_TRANSACTIONS = 'transactions';
	const FEATURE_FULLTEXT = 'fulltext';
	const FEATURE_SLAVE = 'slave';
	/**
	 * @var string
	 */
	protected $host = '';
	/**
	 * @var string
	 */
	protected $dbname = '';
	/**
	 * @var string
	 */
	protected $charset = 'utf8';
	/**
	 * @var string
	 */
	protected $username = '';
	/**
	 * @var string
	 */
	protected $password = '';
	/**
	 * @var int
	 */
	protected $port = 3306;
	/**
	 * @var PDO
	 */
	protected $pdo;
	/**
	 * @var ilDBPdoManager
	 */
	protected $manager;
	/**
	 * @var ilDBPdoReverse
	 */
	protected $reverse;
	/**
	 * @var int
	 */
	protected $limit = null;
	/**
	 * @var int
	 */
	protected $offset = null;
	/**
	 * @var array
	 */
	protected $type_to_mysql_type = array(
		ilDBConstants::T_TEXT      => 'VARCHAR',
		ilDBConstants::T_INTEGER   => 'INT',
		ilDBConstants::T_FLOAT     => 'DOUBLE',
		ilDBConstants::T_DATE      => 'DATE',
		ilDBConstants::T_TIME      => 'TIME',
		ilDBConstants::T_DATETIME  => 'TIMESTAMP',
		ilDBConstants::T_CLOB      => 'LONGTEXT',
		ilDBConstants::T_TIMESTAMP => 'DATETIME',
	);
	/**
	 * @var string
	 */
	protected $dsn = '';
	/**
	 * @var array
	 */
	protected $additional_attributes = array(
		PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
		PDO::ATTR_EMULATE_PREPARES         => true,
		PDO::ATTR_ERRMODE                  => PDO::ERRMODE_EXCEPTION,
		//		PDO::ATTR_DEFAULT_FETCH_MODE       => PDO::FETCH_OBJ
		//		PDO::MYSQL_ATTR_MAX_BUFFER_SIZE => 1048576
	);
	/**
	 * @var string
	 */
	protected $db_type = '';
	/**
	 * @var int
	 */
	protected $error_code = 0;


	/**
	 * @param bool $return_false_for_error
	 * @return bool
	 * @throws \Exception
	 */
	public function connect($return_false_for_error = false) {
		if (!$this->getDSN()) {
			$this->generateDSN();
		}
		try {
			$this->pdo = new PDO($this->getDSN(), $this->getUsername(), $this->getPassword(), $this->additional_attributes);
			$this->manager = new ilDBPdoManager($this->pdo, $this);
			$this->reverse = new ilDBPdoReverse($this->pdo, $this);
		} catch (Exception $e) {
			$this->error_code = $e->getCode();
			if ($return_false_for_error) {
				return false;
			}
			throw $e;
		}

		return ($this->pdo->errorCode() == PDO::ERR_NONE);
	}


	/**
	 * @param $a_name
	 * @param string $a_charset
	 * @param string $a_collation
	 * @return \PDOStatement
	 * @throws \ilDatabaseException
	 */
	public function createDatabase($a_name, $a_charset = "utf8", $a_collation = "") {
		$this->setDbname(null);
		$this->generateDSN();
		$this->connect(true);
		try {
			return $this->query(ilMySQLQueryUtils::getInstance($this)->createDatabase($a_name, $a_charset, $a_collation));
		} catch (PDOException $e) {
			return false;
		}
	}


	/**
	 * @return int
	 */
	public function getLastErrorCode() {
		if ($this->pdo instanceof PDO) {
			return $this->pdo->errorCode();
		}

		return $this->error_code;
	}


	/**
	 * @param null $tmpClientIniFile
	 */
	public function initFromIniFile($tmpClientIniFile = null) {
		global $ilClientIniFile;
		if ($tmpClientIniFile instanceof ilIniFile) {
			$clientIniFile = $tmpClientIniFile;
		} else {
			$clientIniFile = $ilClientIniFile;
		}

		$this->setUsername($clientIniFile->readVariable("db", "user"));
		$this->setHost($clientIniFile->readVariable("db", "host"));
		$this->setPort((int)$clientIniFile->readVariable("db", "port"));
		$this->setPassword($clientIniFile->readVariable("db", "pass"));
		$this->setDbname($clientIniFile->readVariable("db", "name"));
		$this->setDBType($clientIniFile->readVariable("db", "type"));

		$this->generateDSN();
	}


	public function generateDSN() {
		$this->dsn = 'mysql:host=' . $this->getHost() . ($this->getDbname() ? ';dbname=' . $this->getDbname() : '') . ';charset='
		             . $this->getCharset();
	}


	/**
	 * @param $identifier
	 * @return string
	 */
	public function quoteIdentifier($identifier, $check_option = false) {
		return '`' . $identifier . '`';
	}


	/**
	 * @param $table_name string
	 *
	 * @return int
	 */
	public function nextId($table_name) {
		$sequence_table_name = $table_name . '_seq';
		if ($this->tableExists($sequence_table_name)) {
			$stmt = $this->pdo->prepare("SELECT sequence FROM $sequence_table_name");
			$stmt->execute();
			$rows = $stmt->fetch(PDO::FETCH_ASSOC);
			$stmt->closeCursor();
			$has_set = isset($rows['sequence']);
			$next_id = ($has_set ? ($rows['sequence'] + 1) : 1);
			if ($has_set) {
				$stmt = $this->pdo->prepare("UPDATE $sequence_table_name SET sequence = :next_id");
			} else {
				$stmt = $this->pdo->prepare("INSERT INTO $sequence_table_name (sequence) VALUES(:next_id)");
			}
			$stmt->execute(array( "next_id" => $next_id ));

			return $next_id;
		} else {
			return $this->pdo->lastInsertId($this->quoteIdentifier($table_name)) + 1;
		}
	}


	/**
	 * experimental....
	 *
	 * @param $table_name string
	 * @param $fields     array
	 */
	public function createTableLeg($table_name, $fields) {
		$fields_query = $this->createTableFields($fields);
		$query = "CREATE TABLE $table_name ($fields_query);";
		$this->pdo->exec($query);
	}


	/**
	 * @param $table_name
	 * @param $fields
	 * @param bool $drop_table
	 * @param bool $ignore_erros
	 * @return mixed
	 * @throws \ilDatabaseException
	 */
	public function createTable($table_name, $fields, $drop_table = false, $ignore_erros = false) {
		// check table name
		if (!$this->checkTableName($table_name) && !$ignore_erros) {
			throw new ilDatabaseException("ilDB Error: createTable(" . $table_name . ")");
		}

		// check definition array
		if (!$this->checkTableColumns($fields) && !$ignore_erros) {
			throw new ilDatabaseException("ilDB Error: createTable(" . $table_name . ")");
		}

		if ($drop_table) {
			$this->dropTable($table_name);
		}

		return $this->manager->createTable($table_name, $fields, array());
	}


	/**
	 * @param $a_cols
	 * @return bool
	 */
	protected function checkTableColumns($a_cols) {
		foreach ($a_cols as $col => $def) {
			if (!$this->checkColumn($col, $def)) {
				return false;
			}
		}

		return true;
	}


	/**
	 * @param $a_col
	 * @param $a_def
	 * @return bool
	 */
	protected function checkColumn($a_col, $a_def) {
		if (!$this->checkColumnName($a_col)) {
			return false;
		}

		if (!$this->checkColumnDefinition($a_def)) {
			return false;
		}

		return true;
	}


	/**
	 * @param $a_def
	 * @param bool $a_modify_mode
	 * @return bool
	 */
	protected function checkColumnDefinition($a_def, $a_modify_mode = false) {
		return ilDBPdoFieldDefinition::getInstance($this)->checkColumnDefinition($a_def);
	}


	/**
	 * @param $a_name
	 * @return bool
	 */
	public function checkColumnName($a_name) {
		return ilDBPdoFieldDefinition::getInstance($this)->checkColumnName($a_name);
	}


	/**
	 * @param $fields
	 * @deprecated
	 * @return string
	 */
	protected function createTableFields($fields) {
		$query = "";
		foreach ($fields as $name => $field) {
			$type = $this->type_to_mysql_type[$field['type']];
			$length = $field['length'] ? "(" . $field['length'] . ")" : "";
			$primary = isset($field['is_primary']) && $field['is_primary'] ? "PRIMARY KEY" : "";
			$notnull = isset($field['is_notnull']) && $field['is_notnull'] ? "NOT NULL" : "";
			$sequence = isset($field['sequence']) && $field['sequence'] ? "AUTO_INCREMENT" : "";
			$query .= "$name $type $length $sequence $primary $notnull,";
		}

		return substr($query, 0, - 1);
	}


	/**
	 * @param string $table_name
	 * @param array $primary_keys
	 * @return bool
	 * @throws \ilDatabaseException
	 */
	public function addPrimaryKey($table_name, $primary_keys) {
		assert(is_array($primary_keys));

		$fields = array();
		foreach ($primary_keys as $f) {
			$fields[$f] = array();
		}
		$definition = array(
			'primary' => true,
			'fields'  => $fields,
		);
		$this->manager->createConstraint($table_name, $this->constraintName($table_name, $this->getPrimaryKeyIdentifier()), $definition);

		return true;
	}


	/**
	 * @param $atable_name
	 * @param $fields
	 * @return bool|mixed
	 * @throws \ilDatabaseException
	 */
	public function dropIndexByFields($atable_name, $fields) {
		foreach ($this->manager->listTableIndexes($atable_name) as $idx_name) {
			$def = $this->reverse->getTableIndexDefinition($atable_name, $idx_name);
			$idx_fields = array_keys((array)$def['fields']);

			if ($idx_fields === $fields) {
				return $this->dropIndex($atable_name, $idx_name);
			}
		}

		return false;
	}


	/**
	 * @return string
	 */
	public function getPrimaryKeyIdentifier() {
		return "PRIMARY";
	}


	/**
	 * @param $table_name
	 * @param int $start
	 */
	public function createSequence($table_name, $start = 1) {
		$this->manager->createSequence($table_name, $start);
	}


	/**
	 * @param $table_name string
	 *
	 * @return bool
	 */
	public function tableExists($table_name) {
		$result = $this->pdo->prepare("SHOW TABLES LIKE :table_name");
		$result->execute(array( 'table_name' => $table_name ));
		$return = $result->rowCount();
		$result->closeCursor();

		return $return > 0;
	}


	/**
	 * @param $table_name  string
	 * @param $column_name string
	 *
	 * @return bool
	 */
	public function tableColumnExists($table_name, $column_name) {
		$statement = $this->pdo->query("SHOW COLUMNS FROM $table_name WHERE Field = '$column_name'");
		$statement != null ? $statement->closeCursor() : "";

		return $statement != null && $statement->rowCount() != 0;
	}


	/**
	 * @param $table_name  string
	 * @param $column_name string
	 * @param $attributes  array
	 */
	public function addTableColumn($table_name, $column_name, $attributes) {
		$col = array( $column_name => $attributes );
		$col_str = $this->createTableFields($col);
		$this->pdo->exec("ALTER TABLE $table_name ADD $col_str");
	}


	/**
	 * @param $table_name
	 * @param bool $error_if_not_existing
	 * @return int
	 */
	public function dropTable($table_name, $error_if_not_existing = true) {
		try {
			$this->pdo->exec("DROP TABLE $table_name");
		} catch (PDOException $PDOException) {
			if ($error_if_not_existing) {
				throw $PDOException;
			}

			return false;
		}

		return true;
	}


	/**
	 * @param $query string
	 * @return PDOStatement
	 * @throws ilDatabaseException
	 */
	public function query($query) {
		$query = $this->appendLimit($query);

		try {
			$res = $this->pdo->query($query);
		} catch (PDOException $e) {
			throw new ilDatabaseException($e->getMessage() . ' QUERY: ' . $query);
		}

		$err = $this->pdo->errorCode();
		if ($err != PDO::ERR_NONE) {
			$info = $this->pdo->errorInfo();
			$info_message = $info[2];
			throw new ilDatabaseException($info_message . ' QUERY: ' . $query);
		}

		return new ilPDOStatement($res);
	}


	/**
	 * @param $query_result PDOStatement
	 *
	 * @return array
	 */
	public function fetchAll($query_result) {
		return $query_result->fetchAll($query_result);
	}


	/**
	 * @param $table_name string
	 */
	public function dropSequence($table_name) {
		$this->manager->dropSequence($table_name);
		//		$table_seq = $table_name . "_seq";
		//		if ($this->tableExists($table_seq)) {
		//			$this->pdo->exec("DROP TABLE $table_seq");
		//		}
	}


	/**
	 * @param string $table_name
	 * @param string $column_name
	 * @return bool
	 * @throws \ilDatabaseException
	 */
	public function dropTableColumn($table_name, $column_name) {
		$changes = array(
			"remove" => array(
				$column_name => array(),
			),
		);

		return $this->manager->alterTable($table_name, $changes, false);
	}


	/**
	 * @param string $table_name
	 * @param string $column_old_name
	 * @param string $column_new_name
	 * @return bool
	 * @throws \ilDatabaseException
	 */
	public function renameTableColumn($table_name, $column_old_name, $column_new_name) {
		// check table name
		if (!$this->checkColumnName($column_new_name)) {
			throw new ilDatabaseException("ilDB Error: renameTableColumn(" . $table_name . "," . $column_old_name . "," . $column_new_name . ")");
		}

		$def = $this->reverse->getTableFieldDefinition($table_name, $column_old_name);

		$analyzer = new ilDBAnalyzer($this);
		$best_alt = $analyzer->getBestDefinitionAlternative($def);
		$def = $def[$best_alt];
		unset($def["nativetype"]);
		unset($def["mdb2type"]);

		$f["definition"] = $def;
		$f["name"] = $column_new_name;

		$changes = array(
			"rename" => array(
				$column_old_name => $f,
			),
		);

		return $this->manager->alterTable($table_name, $changes, false);
		//
		//
		//
		//
		//		$get_type_query = "SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = " . $this->quote($table_name, 'text')
		//		                  . " AND COLUMN_NAME = " . $this->quote($column_old_name, 'text');
		//		$get_type_result = $this->query($get_type_query);
		//		$column_type = $this->fetchAssoc($get_type_result);
		//
		//		$query = "ALTER TABLE $table_name CHANGE " . $this->quote($column_old_name, 'text') . " " . $this->quote($column_new_name, 'text') . " "
		//		         . $column_type['COLUMN_TYPE'];
		//		$this->pdo->exec($query);
	}


	/**
	 * @param $table_name string
	 * @param $values
	 * @return int|void
	 */
	public function insert($table_name, $values) {
		$real = array();
		$fields = array();
		foreach ($values as $key => $val) {
			$real[] = $this->quote($val[1], $val[0]);
			$fields[] = $key;
		}
		$values = implode(",", $real);
		$fields = implode(",", $fields);
		$query = "INSERT INTO " . $table_name . " (" . $fields . ") VALUES (" . $values . ")";

		return $this->pdo->exec($query);
	}


	/**
	 * @param $query_result PDOStatement
	 *
	 * @return mixed|null
	 */
	public function fetchObject($query_result) {
		$res = $query_result->fetchObject();
		if ($res == null) {
			$query_result->closeCursor();

			return null;
		}

		return $res;
	}


	/**
	 * @param $table_name string
	 * @param $values     array
	 * @param $where      array
	 * @return int|void
	 */
	public function update($table_name, $values, $where) {

		$query_fields = array();
		foreach ($values as $key => $val) {
			$qval = $this->quote($val[1], $val[0]);
			$query_fields[] = "$key = $qval";
		}

		$query_where = array();
		foreach ($where as $key => $val) {
			$qval = $this->quote($val[1], $val[0]);
			$query_where[] = "$key = $qval";
		}

		$query = "UPDATE $table_name" . " SET " . implode(", ", $query_fields) . " WHERE " . implode(" AND ", $query_where);

		return $this->manipulate($query);
	}


	/**
	 * @param string $query
	 * @return bool|int
	 * @throws \ilDatabaseException
	 */
	public function manipulate($query) {
		try {
			$r = $this->pdo->exec($query);
		} catch (PDOException $e) {
			return false;
			throw new ilDatabaseException($e->getMessage() . ' QUERY: ' . $query);
		}

		return $r;
	}


	/**
	 * @param $query_result PDOStatement
	 *
	 * @return mixed
	 */
	public function fetchAssoc($query_result) {
		$res = $query_result->fetch(PDO::FETCH_ASSOC);
		if ($res == null) {
			$query_result->closeCursor();

			return null;
		}

		return $res;
	}


	/**
	 * @param $query_result PDOStatement
	 *
	 * @return int
	 */
	public function numRows($query_result) {
		return $query_result->rowCount();
	}


	/**
	 * @param $value
	 * @param $type
	 *
	 * @return mixed
	 */
	public function quote($value, $type = null) {
		if ($value === null) {
			return 'NULL';
		}

		switch ($type) {
			case ilDBConstants::T_INTEGER:
				$pdo_type = PDO::PARAM_INT;
				$value = (int)$value;
				if ($value === 1) {
					return 1;
				}
				break;
			case ilDBConstants::T_FLOAT:
				$pdo_type = PDO::PARAM_INT;
				break;
			case ilDBConstants::T_TEXT:
			default:
				$pdo_type = PDO::PARAM_STR;
				break;
		}

		return $this->pdo->quote($value, $pdo_type);
	}


	/**
	 * @param string $table_name
	 * @param array $fields
	 *
	 * @return null
	 */
	public function indexExistsByFields($table_name, $fields) {
		foreach ($this->manager->listTableIndexes($table_name) as $idx_name) {
			$def = $this->reverse->getTableIndexDefinition($table_name, $idx_name);
			$idx_fields = array_keys((array)$def['fields']);

			if ($idx_fields === $fields) {
				return true;
			}
		}

		return false;
	}


	/**
	 * @param $table_name
	 * @param array $fields
	 * @param $index_name
	 * @return null
	 */
	public function addIndex($table_name, $fields, $index_name = '', $fulltext = false) {
		assert(is_array($fields));
		ilDBPdoFieldDefinition::getInstance($this)->checkIndexName($index_name);

		$definition_fields = array();
		foreach ($fields as $f) {
			$definition_fields[$f] = array();
		}
		$definition = array(
			'fields' => $definition_fields,
		);

		if (!$fulltext) {
			$this->manager->createIndex($table_name, $this->constraintName($table_name, $index_name), $definition);
		} else {
			if ($this->supportsFulltext()) {
				$this->addFulltextIndex($table_name, $fields, $index_name); // TODO
			}
		}

		return true;
	}


	/**
	 * @param $index_name_base
	 * @return string
	 */
	public function getIndexName($index_name_base) {
		return sprintf(ilDBPdoFieldDefinition::INDEX_FORMAT, preg_replace('/[^a-z0-9_\$]/i', '_', $index_name_base));
	}


	/**
	 * @param $table_name
	 * @return string
	 */
	public function getSequenceName($table_name) {
		return sprintf(ilDBPdoFieldDefinition::SEQUENCE_FORMAT, preg_replace('/[^a-z0-9_\$.]/i', '_', $table_name));
	}


	/**
	 * Determine contraint name by table name and constraint name.
	 * In MySQL these are "unique" per table, but they
	 * must be "globally" unique in oracle. (so this one is overwritten there)
	 */
	public function constraintName($a_table, $a_constraint) {
		return $a_constraint;
	}


	public function addFulltextIndex() {
	}


	/**
	 * @param $fetchMode int
	 * @return mixed
	 * @throws ilDatabaseException
	 */
	public function fetchRow($fetchMode = ilDBConstants::FETCHMODE_ASSOC) {
		if ($fetchMode == ilDBConstants::FETCHMODE_ASSOC) {
			return $this->fetchRowAssoc();
		} elseif ($fetchMode == ilDBConstants::FETCHMODE_OBJECT) {
			return $this->fetchRowObject();
		} else {
			throw new ilDatabaseException("No valid fetch mode given, choose ilDBConstants::FETCHMODE_ASSOC or ilDBConstants::FETCHMODE_OBJECT");
		}
	}


	private function fetchRowAssoc() {
	}


	private function fetchRowObject() {
	}


	/**
	 * @return string
	 */
	public function getDSN() {
		return $this->dsn;
	}


	/**
	 * @return string
	 */
	public function getDBType() {
		return $this->db_type;
	}


	/**
	 * @param string $type
	 */
	public function setDBType($type) {
		$this->db_type = $type;
	}


	/**
	 * Get reserved words. This must be overwritten in DBMS specific class.
	 * This is mainly used to check whether a new identifier can be problematic
	 * because it is a reserved word. So createTable / alterTable usually check
	 * these.
	 */
	static function getReservedWords() {
		global $ilDB;

		return ilDBPdoFieldDefinition::getInstance($ilDB)->getReserved();
	}


	/**
	 * @param array $tables
	 */
	public function lockTables($tables) {
		assert(is_array($tables));

		$lock = ilMySQLQueryUtils::getInstance($this)->lock($tables);
		global $ilLog;
		if ($ilLog instanceof ilLog) {
			$ilLog->write('ilDB::lockTables(): ' . $lock);
		}

		$this->query($lock);
	}


	public function unlockTables() {
		$this->query(ilMySQLQueryUtils::getInstance($this)->unlock());
	}


	/**
	 * @param $field  string
	 * @param $values array
	 * @param bool $negate
	 * @param string $type
	 * @return string
	 */
	public function in($field, $values, $negate = false, $type = "") {
		return ilMySQLQueryUtils::getInstance($this)->in($field, $values, $negate, $type);
	}


	/**
	 * @param string $query
	 * @param \string[] $types
	 * @param \mixed[] $values
	 * @return \PDOStatement
	 * @throws \ilDatabaseException
	 */
	public function queryF($query, $types, $values) {
		if (!is_array($types) || !is_array($values) || count($types) != count($values)) {
			throw new ilDatabaseException("ilDB::queryF: Types and values must be arrays of same size. ($query)");
		}
		$quoted_values = array();
		foreach ($types as $k => $t) {
			$quoted_values[] = $this->quote($values[$k], $t);
		}
		$query = vsprintf($query, $quoted_values);

		return $this->query($query);
	}


	/**
	 * @param $query  string
	 * @param $types  string[]
	 * @param $values mixed[]
	 * @return string
	 * @throws ilDatabaseException
	 */
	public function manipulateF($query, $types, $values) {
		if (!is_array($types) || !is_array($values) || count($types) != count($values)) {
			throw new ilDatabaseException("ilDB::manipulateF: types and values must be arrays of same size. ($query)");
		}
		$quoted_values = array();
		foreach ($types as $k => $t) {
			$quoted_values[] = $this->quote($values[$k], $t);
		}
		$query = vsprintf($query, $quoted_values);

		return $this->manipulate($query);
	}


	/**
	 * @param $bool
	 * @return bool
	 *
	 * TODO
	 */
	public function useSlave($bool) {
		return false;
	}


	/**
	 * Set the Limit for the next Query.
	 *
	 * @param $limit
	 * @param $offset
	 * @deprecated Use a limit in the query.
	 */
	public function setLimit($limit, $offset = 0) {
		$this->limit = $limit;
		$this->offset = $offset;
	}


	/**
	 * @param string $column
	 * @param string $type
	 * @param string $value
	 * @param bool $case_insensitive
	 * @return string
	 * @throws \ilDatabaseException
	 */
	public function like($column, $type, $value = "?", $case_insensitive = true) {
		return ilMySQLQueryUtils::getInstance($this)->like($column, $type, $value, $case_insensitive);
	}


	/**
	 * @return string the now statement
	 */
	public function now() {
		return ilMySQLQueryUtils::getInstance($this)->now();
	}


	/**
	 * Replace into method.
	 *
	 * @param    string        table name
	 * @param    array         primary key values: array("field1" => array("text", $name), "field2" => ...)
	 * @param    array         other values: array("field1" => array("text", $name), "field2" => ...)
	 * @return string
	 */
	public function replace($table, $primaryKeys, $otherColumns) {
		$a_columns = array_merge($primaryKeys, $otherColumns);
		$fields = array();
		$field_values = array();
		$placeholders = array();
		$types = array();
		$values = array();

		foreach ($a_columns as $k => $col) {
			$fields[] = $k;
			$placeholders[] = "%s";
			$placeholders2[] = ":$k";
			$types[] = $col[0];

			// integer auto-typecast (this casts bool values to integer)
			if ($col[0] == 'integer' && !is_null($col[1])) {
				$col[1] = (int)$col[1];
			}

			$values[] = $col[1];
			$field_values[$k] = $col[1];
		}

		$q = "REPLACE INTO " . $table . " (" . implode($fields, ",") . ") VALUES (" . implode($placeholders, ",") . ")";

		$r = $this->manipulateF($q, $types, $values);

		return $r;
	}


	/**
	 * @param $columns
	 * @param $value
	 * @param $type
	 * @param bool $emptyOrNull
	 * @return string
	 */
	public function equals($columns, $value, $type, $emptyOrNull = false) {
		if (!$emptyOrNull || $value != "") {
			return $columns . " = " . $this->quote($value, $type);
		} else {
			return "(" . $columns . " = '' OR $columns IS NULL)";
		}
	}


	/**
	 * @return string
	 */
	public function getHost() {
		return $this->host;
	}


	/**
	 * @param string $host
	 */
	public function setHost($host) {
		$this->host = $host;
	}


	/**
	 * @return string
	 */
	public function getDbname() {
		return $this->dbname;
	}


	/**
	 * @param string $dbname
	 */
	public function setDbname($dbname) {
		$this->dbname = $dbname;
	}


	/**
	 * @return string
	 */
	public function getCharset() {
		return $this->charset;
	}


	/**
	 * @param string $charset
	 */
	public function setCharset($charset) {
		$this->charset = $charset;
	}


	/**
	 * @return string
	 */
	public function getUsername() {
		return $this->username;
	}


	/**
	 * @param string $username
	 */
	public function setUsername($username) {
		$this->username = $username;
	}


	/**
	 * @return string
	 */
	public function getPassword() {
		return $this->password;
	}


	/**
	 * @param string $password
	 */
	public function setPassword($password) {
		$this->password = $password;
	}


	/**
	 * @return int
	 */
	public function getPort() {
		return $this->port;
	}


	/**
	 * @param int $port
	 */
	public function setPort($port) {
		$this->port = $port;
	}


	/**
	 * @param $user
	 */
	public function setDBUser($user) {
		$this->setUsername($user);
	}


	/**
	 * @param $port
	 */
	public function setDBPort($port) {
		$this->setPort($port);
	}


	/**
	 * @param $password
	 */
	public function setDBPassword($password) {
		$this->setPassword($password);
	}


	/**
	 * @param $host
	 */
	public function setDBHost($host) {
		$this->setHost($host);
	}


	/**
	 * @param $a_exp
	 * @return string
	 */
	public function upper($a_exp) {
		return " UPPER(" . $a_exp . ") ";
	}


	/**
	 * @param $a_exp
	 * @return string
	 */
	public function lower($a_exp) {
		return " LOWER(" . $a_exp . ") ";
	}


	/**
	 * @param $a_exp
	 * @param int $a_pos
	 * @param int $a_len
	 * @return string
	 */
	public function substr($a_exp, $a_pos = 1, $a_len = - 1) {
		$lenstr = "";
		if ($a_len > - 1) {
			$lenstr = ", " . $a_len;
		}

		return " SUBSTR(" . $a_exp . ", " . $a_pos . $lenstr . ") ";
	}


	/**
	 * @param $a_query
	 * @param null $a_types
	 * @return ilDBStatement
	 */
	public function prepareManip($a_query, $a_types = null) {
		return $this->pdo->prepare($a_query);
	}


	public function enableResultBuffering($a_status) {
		$this->pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, $a_status);
	}


	/**
	 * @param $stmt
	 * @param array $data
	 * @return bool
	 */
	public function execute($stmt, $data = array()) {
		/**
		 * @var $stmt PDOStatement
		 */
		return $stmt->execute($data);
	}


	/**
	 * @param $a_table
	 * @return \PDOStatement
	 * @throws \ilDatabaseException
	 */
	public function optimizeTable($a_table) {
		return $this->query('OPTIMIZE TABLE ' . $a_table);
	}


	/**
	 * @return bool
	 */
	public function supportsSlave() {
		return false;
	}


	/**
	 * @return bool
	 */
	public function supportsFulltext() {
		return false;
	}


	/**
	 * @return bool
	 */
	public function supportsTransactions() {
		return false;
	}


	/**
	 * @param $feature
	 * @return bool
	 */
	public function supports($feature) {
		switch ($feature) {
			case self::FEATURE_TRANSACTIONS:
				return $this->supportsTransactions();
			case self::FEATURE_FULLTEXT:
				return $this->supportsFulltext();
			case self::FEATURE_SLAVE:
				return $this->supportsSlave();
			default:
				return false;
		}
	}


	/**
	 * @return array
	 */
	public function listTables() {
		return $this->manager->listTables();
	}


	/**
	 * @param $module
	 * @return \ilDBPdoManager|\ilDBPdoReverse
	 */
	public function loadModule($module) {
		switch ($module) {
			case ilDBConstants::MODULE_MANAGER:
				return $this->manager;
			case ilDBConstants::MODULE_REVERSE:
				return $this->reverse;
		}
	}


	/**
	 * @return array
	 */
	public function getAllowedAttributes() {
		return ilDBPdoFieldDefinition::getInstance($this)->getAllowedAttributes();
	}


	/**
	 * @param $sequence
	 * @return bool
	 */
	public function sequenceExists($sequence) {
		return in_array($sequence, $this->listSequences());
	}


	/**
	 * @return array
	 */
	public function listSequences() {
		return $this->manager->listSequences();
	}


	/**
	 * @param array $values
	 * @param bool $allow_null
	 * @return string
	 */
	public function concat(array $values, $allow_null = true) {
		return ilMySQLQueryUtils::getInstance()->concat($values, $allow_null);
	}


	/**
	 * @param $query
	 * @return string
	 */
	protected function appendLimit($query) {
		if ($this->limit !== null && $this->offset !== null) {
			$query .= ' LIMIT ' . (int)$this->offset . ', ' . (int)$this->limit;
			$this->limit = null;
			$this->offset = null;

			return $query;
		}

		return $query;
	}


	/**
	 * @param $a_needle
	 * @param $a_string
	 * @param int $a_start_pos
	 * @return string
	 */
	public function locate($a_needle, $a_string, $a_start_pos = 1) {
		return ilMySQLQueryUtils::getInstance()->locate($a_needle, $a_string, $a_start_pos);
	}


	/**
	 * @param $table
	 * @param $a_column
	 * @param $a_attributes
	 * @return bool
	 */
	public function modifyTableColumn($table, $a_column, $a_attributes) {
		$def = $this->reverse->getTableFieldDefinition($table, $a_column);

		$analyzer = new ilDBAnalyzer($this);
		$best_alt = $analyzer->getBestDefinitionAlternative($def);
		$def = $def[$best_alt];
		unset($def["nativetype"]);
		unset($def["mdb2type"]);

		// check attributes
		$ilDBPdoFieldDefinition = ilDBPdoFieldDefinition::getInstance($this);

		$type = ($a_attributes["type"] != "") ? $a_attributes["type"] : $def["type"];
		foreach ($def as $k => $v) {
			if ($k != "type" && !$ilDBPdoFieldDefinition->isAllowedAttribute($k, $type)) {
				unset($def[$k]);
			}
		}
		$check_array = $def;
		foreach ($a_attributes as $k => $v) {
			$check_array[$k] = $v;
		}
		if (!$this->checkColumnDefinition($check_array, true)) {
			throw new ilDatabaseException("ilDB Error: modifyTableColumn(" . $table . ", " . $a_column . ")");
		}

		foreach ($a_attributes as $a => $v) {
			$def[$a] = $v;
		}

		$a_attributes["definition"] = $def;

		$changes = array(
			"change" => array(
				$a_column => $a_attributes,
			),
		);

		return $this->manager->alterTable($table, $changes, false);
	}


	/**
	 * @param ilPDOStatement $a_st
	 * @return bool
	 */
	public function free($a_st) {
		/**
		 * @var $a_st PDOStatement
		 */
		return $a_st->closeCursor();
	}


	/**
	 * @param $a_name
	 * @param $a_new_name
	 * @return bool
	 * @throws \ilDatabaseException
	 */
	public function renameTable($a_name, $a_new_name) {
		// check table name
		try {
			$this->checkTableName($a_new_name);
		} catch (ilDatabaseException $e) {
			throw new ilDatabaseException("ilDB Error: renameTable(" . $a_name . "," . $a_new_name . ")<br />" . $e->getMessage());
		}

		$this->manager->alterTable($a_name, array( "name" => $a_new_name ), false);

		$query = "UPDATE abstraction_progress " . "SET table_name = " . $this->quote($a_new_name, 'text') . " " . "WHERE table_name = "
		         . $this->quote($a_name, 'text');
		$this->pdo->query($query);

		return true;
	}


	/**
	 * @param $a_name
	 * @return bool
	 * @throws \ilDatabaseException
	 */
	public function checkTableName($a_name) {
		return ilDBPdoFieldDefinition::getInstance($this)->checkTableName($a_name);
	}


	/**
	 * @param $a_word
	 * @return bool
	 */
	public static function isReservedWord($a_word) {
		global $ilDB;

		return ilDBPdoFieldDefinition::getInstance($ilDB)->isReserved($a_word);
	}


	/**
	 * @return bool
	 * @throws \ilDatabaseException
	 */
	public function beginTransaction() {
		if (!$this->supports(self::FEATURE_TRANSACTIONS)) {
			throw new ilDatabaseException("ilDB::beginTransaction: Transactions are not supported.");
		}

		return $this->pdo->beginTransaction();
	}


	/**
	 * @return bool
	 * @throws \ilDatabaseException
	 */
	public function commit() {
		if (!$this->supports(self::FEATURE_TRANSACTIONS)) {
			throw new ilDatabaseException("ilDB::beginTransaction: Transactions are not supported.");
		}

		return $this->pdo->commit();
	}


	/**
	 * @return bool
	 * @throws \ilDatabaseException
	 */
	public function rollback() {
		if (!$this->supports(self::FEATURE_TRANSACTIONS)) {
			throw new ilDatabaseException("ilDB::beginTransaction: Transactions are not supported.");
		}

		return $this->pdo->rollBack();
	}


	/**
	 * @param $a_table
	 * @param string $a_name
	 * @return mixed
	 */
	public function dropIndex($a_table, $a_name = "i1") {
		return $this->manager->dropIndex($a_table, $a_name);
	}
}
