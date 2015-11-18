<?php

/**
 * 使用之前要定义好一下几个常量
 * MYSQL_HOST;MYSQL_PORT;MYSQL_USER;MYSQL_PASS;MYSQL_DB;
 * 默认的字符集是utf8
 * @author huangyifu
 *
 */
class Mysql {

	/**
	 * 构造函数
	 *
	 * @param bool $do_replication
	 *        	是否支持主从分离，true:支持，false:不支持，默认为true
	 * @return void
	 */
	function __construct($host = MYSQL_HOST, $username = MYSQL_USER, $passwd = MYSQL_PASS, $dbname = MYSQL_DB, $port = MYSQL_PORT) {
		$this -> host = $host;
		$this -> port = $port;
		$this -> username = $username;
		$this -> passwd = $passwd;
		$this -> dbname = $dbname;

		// set default charset as utf8
		$this -> charset = 'utf8';
		// 使用下划线格式作为列名和表名
		//		$this -> underScore = false;
		//		$this -> resultCase = '';
		$this -> validateColumnName = true;
	}

	/**
	 * 设置当前连接的字符集 , 必须在发起连接之前进行设置
	 *
	 * @param string $charset
	 *        	字符集,如GBK,GB2312,UTF8
	 * @return void
	 */
	public function setCharset($charset) {
		$this -> charset = $charset;
	}

	//	public function setUnderScore($mode = TRUE) {
	//		$this -> underScore = $mode;
	//	}

	//	public function setResultCase($mode = 'camel') {// 'camel'
	//		$this -> resultCase = $mode;
	//	}

	public function setValidateColumnName($mode) {
		$this -> validateColumnName = $mode;
	}

	public function autocommit($autocommit) {
		$this -> autocommit = $autocommit;
		$dblink = $this -> connect();
		mysqli_autocommit($dblink, $autocommit);
	}

	public function commit() {
		if ($this -> autocommit === false && isset($this -> db_link)) {
			mysqli_commit($this -> db_link);
		}
	}

	public function rollback() {
		if ($this -> autocommit === false && isset($this -> db_link)) {
			mysqli_rollback($this -> db_link);
		}
	}

	/**
	 * 运行Sql语句,不返回结果集
	 *
	 * @param string $sql
	 * @return mysqli_result|bool
	 */
	public function runSql($sql) {
		$this -> last_sql = $sql;
		$this -> sqls++;
		$dblink = $this -> connect();
		if ($dblink === false) {
			return false;
		}
		$ret = mysqli_query($dblink, $sql);
		$this -> save_error($dblink);
		$this -> debug();
		return $ret;
	}

	/**
	 * 运行Sql,以多维数组方式返回结果集
	 *
	 * @param string $sql
	 * @return array 成功返回数组，失败时返回false
	 * @author EasyChen
	 */
	public function getData($sql) {
		$this -> last_sql = $sql;
		$this -> sqls++;
		$data = Array();
		$i = 0;
		$dblink = $this -> connect();
		if ($dblink === false) {
			return false;
		}
		$result = mysqli_query($dblink, $sql);

		$this -> save_error($dblink);

		if (is_bool($result)) {
			$this -> debug(null, $data);
			return $result;
		} else {
			$finfo = mysqli_fetch_fields($result);
			$ff = array();
			foreach ($finfo as $fi) {
				if ($fi -> type === MYSQLI_TYPE_TINY && $fi -> max_length == 1 && (preg_match("/^(is|has|can|require|need|should|enable|disable).*/i", $fi -> name) || preg_match("/.*(enabled|disabled|required)$/i", $fi -> name))) {
					$ff[$fi -> name] = 1;
					// boolean
				} elseif ($fi -> type == MYSQLI_TYPE_TINY || $fi -> type == MYSQLI_TYPE_SHORT || $fi -> type == MYSQLI_TYPE_LONG || $fi -> type == MYSQLI_TYPE_LONGLONG || $fi -> type == MYSQLI_TYPE_INT24) {
					$ff[$fi -> name] = 2;
					// int
				} elseif ($fi -> type === MYSQLI_TYPE_DECIMAL || $fi -> type == MYSQLI_TYPE_FLOAT || $fi -> type == MYSQLI_TYPE_DOUBLE || $fi -> type == MYSQLI_TYPE_NEWDECIMAL) {
					$ff[$fi -> name] = 3;
					// float
				}
			}

			$row = mysqli_fetch_array($result, MYSQL_ASSOC);
			while ($row) {
				$newArray = array();
				$colNames = array();
				foreach ($row as $key => $value) {
					if ($value !== NULL) {
						if ($ff[$key] === 1) {
							$value = !!$value;
						} elseif ($ff[$key] === 2) {
							$value = intval($value);
							// 整型
						} elseif ($ff[$key] === 3) {
							$value = floatval($value);
							// 浮点
						}
					}
					//					if ($this -> resultCase === 'camel') {
					//把结果集中字段名变成指定的格式
					//						if (empty($colNames[$key])) {
					//							$colNames[$key] = $this -> toCamelCase($key);
					//						}
					//						$newArray[$colNames[$key]] = $value;
					//					} else {
					$newArray[$key] = $value;
					//					}
				}
				$data[$i++] = $newArray;
				$row = mysqli_fetch_array($result, MYSQL_ASSOC);
			}
		}

		mysqli_free_result($result);
		$this -> debug(null, $data);
		// echo json_encode ( $data );
		if ($this -> errno)
			return NULL;
		else
			return $data;
	}

	/**
	 * 运行Sql,以数组方式返回结果集第一条记录
	 *
	 * @param string $sql
	 * @return array 成功返回数组，失败时返回false
	 * @author EasyChen
	 */
	public function getLine($sql) {
		$data = $this -> getData($sql);
		//reset() 函数把数组的内部指针指向第一个元素，并返回这个元素的值。若失败，则返回 FALSE。
		return @reset($data);
	}

	/**
	 * 运行Sql,返回结果集第一条记录的第一个字段值
	 *
	 * @param string $sql
	 * @return mixxed 成功时返回一个值，失败时返回false
	 * @author EasyChen
	 */
	public function getVar($sql) {
		$data = $this -> getLine($sql);
		if ($data) {
			return $data[@reset(@array_keys($data))];
		} else {
			return FALSE;
		}
	}

	/**
	 * 同mysqli_affected_rows函数
	 *
	 * @return int 成功返回行数,失败时返回-1
	 * @author Elmer Zhang
	 */
	public function affectedRows() {
		return mysqli_affected_rows($this -> db_link);
	}

	/**
	 * 同mysqli_insert_id函数
	 *
	 * @return int 成功返回last_id,失败时return zero
	 * @author EasyChen
	 */
	public function lastId() {
		return mysqli_insert_id($this -> db_link);
	}

	/**
	 * 关闭数据库连接
	 *
	 * @return bool
	 * @author EasyChen
	 */
	public function closeDb() {
		if (isset($this -> db_link)) {
			$this -> rollback();
			@mysqli_close($this -> db_link);
		}
	}

	/**
	 * 同mysqli_real_escape_string
	 *
	 * @param string $str
	 * @return string
	 * @author EasyChen
	 */
	public function escape($str) {
		$search = array("\\", "\x00", "\r", "\n", "'", "\"", "\x1a");
		$replace = array("\\\\", "\\0", "\\r", "\\n", "\\'", "\\\"", "\\Z");
		return str_replace($search, $replace, $str);
	}

	public function realEscape($str) {
		$dblink = $this -> connect();
		return mysqli_real_escape_string($dblink, $str);
	}

	/**
	 * 返回错误码
	 *
	 *
	 * @return int
	 * @author EasyChen
	 */
	public function errno() {
		return $this -> errno;
	}

	/**
	 * 返回错误信息
	 *
	 * @return string
	 * @author EasyChen
	 */
	public function error() {
		return $this -> error;
	}

	/**
	 *
	 * @ignore
	 *
	 */
	private function connect() {
		if (isset($this -> db_link) && mysqli_ping($this -> db_link)) {
			return $this -> db_link;
		}

		if ($this -> port == 0) {
			$this -> error = 13048;
			$this -> errno = 'Not Initialized';
			return false;
		}

		$this -> db_link = mysqli_init();
		mysqli_options($this -> db_link, MYSQLI_OPT_CONNECT_TIMEOUT, 5);

		if (!mysqli_real_connect($this -> db_link, $this -> host, $this -> username, $this -> passwd, $this -> dbname, $this -> port)) {
			$this -> error = mysqli_connect_error();
			$this -> errno = mysqli_connect_errno();
			return false;
		}

		mysqli_set_charset($this -> db_link, $this -> charset);

		return $this -> db_link;
	}

	/**
	 *
	 * @ignore
	 *
	 */
	private function save_error($dblink) {
		$this -> error = mysqli_error($dblink);
		$this -> errno = mysqli_errno($dblink);
	}

	public function lastSql() {
		return $this -> last_sql;
	}

	private $error;
	private $errno;
	private $last_sql;
	private $sqls = 0;

	public function countSQL() {
		return $this -> sqls;
	}

	public function debug($mode = NULL, $data = NULL) {
		if ($mode === null && $this -> is_debug) {
			echo "\nROWS:" . $this -> affectedRows() . ', ERRNO: ' . $this -> errno . ', ERROR: ' . $this -> error . "; \nSQL: " . $this -> last_sql . "\nDATA: ";
			var_dump($data);
			echo "\n";
		} elseif ($mode === TRUE) {
			$this -> is_debug = TRUE;
		} elseif ($mode === FALSE) {
			$this -> is_debug = FALSE;
		}
	}

	/**
	 *
	 * @param type $sql
	 * @param type $mysql
	 * @return array 以一维数组的形式返回结果，value：结果集中的第一列值。
	 */
	function getArray($sql) {
		$rtn = array();
		$rs = $this -> getData($sql);
		if (!empty($rs)) {
			$key1 = NULL;
			foreach ($rs as $r) {
				if ($key1 == NULL) {
					$keys = array_keys($r);
					$key1 = $keys[0];
				}
				$rtn[] = $r[$key1];
			}
		}
		return $rtn;
	}

	/**
	 *
	 * @param type $sql
	 * @param type $mysql
	 * @return array 以key=>value数组的形式返回结果，key：结果集中的第一列值，value：结果集中的第二列值
	 */
	function getArray2($sql) {
		$rtn = array();
		$rs = $this -> getData($sql);
		if (!empty($rs)) {
			$keys = NULL;
			foreach ($rs as $r) {
				if ($keys == NULL) {
					$keys = array_keys($r);
				}
				$rtn[$r[$keys[0]]] = $r[$keys[1]];
			}
		}
		return $rtn;
	}

	function getArray3($sql) {
		$rtn = array();
		$rs = $this -> getData($sql);
		if (!empty($rs)) {
			$keys = NULL;
			foreach ($rs as $r) {
				if ($keys == NULL) {
					$keys = array_keys($r);
				}
				// if ($rtn [$r [$keys [0]]] == NULL) {
				// $rtn [$r [$keys [0]]] = array ();
				// }
				$rtn[$r[$keys[0]]][$r[$keys[1]]] = $r[$keys[2]];
			}
		}
		// var_dump($rtn);
		return $rtn;
	}

	function get_columns($tableName) {
		//		if ($this -> underScore === TRUE) {
		//			$tableName = $this -> toUnderScoreCase($tableName);
		//		}
		if (isset($this -> columnsCache[$tableName])) {
			return $this -> columnsCache[$tableName];
		}

		$data = $this -> getData("DESCRIBE " . $this -> escapeColumnName($tableName));
		if (empty($data)) {
			return array();
		}
		$cols = array();
		foreach ($data as $d) {
			$cols[$d['field']] = $d;
		}
		$this -> columnsCache[$tableName] = $cols;
		return $this -> columnsCache[$tableName];
	}

	/**
	 * 返回生成的SELECT SQL
	 */
	private function selectSql($tableName, $cols, $where = NULL, $postfix = NULL, $distinct = FALSE) {
		//		if ($this -> underScore === TRUE) {
		//			$tableName = $this -> toUnderScoreCase($tableName);
		//		}
		if (is_array($cols)) {
			$fields = array();
			foreach ($cols as $index => $name) {
				//				if ($this -> underScore === TRUE) {
				//					$name = $this -> toUnderScoreCase($name);
				//				}
				$name = $this -> escapeColumnName($name);

				if (is_int($index)) {
					$fields[] = $name;
				} else {
					//					if ($this -> underScore === TRUE) {
					//						$index = $this -> toUnderScoreCase($index);
					//					}
					$index = $this -> escapeColumnName($index);
					$fields[] = "{$index} AS {$name}";
				}
			}
			$cols = implode(",", $fields);
		} else {
			//			if ($this -> underScore === TRUE) {
			//				$cols = $this -> toUnderScoreCase($cols);
			//			}
		}

		$sql = "SELECT " . ($distinct === TRUE ? " DISTINCT " : " ") . " $cols FROM " . $this -> escapeColumnName($tableName);
		$w = $this -> where($where);
		if ($w) {
			$sql .= " WHERE " . $w;
		}
		if (is_array($postfix) && count($postfix) > 0) {
			// 放order by
			$newPf = NULL;
			$LIMIT = "";
			foreach ($postfix as $col => $sort) {
				if ($col === '#') {
					$LIMIT = " LIMIT " . $sort;
				} elseif ($newPf === NULL) {
					//					$newPf = " ORDER BY " . $this -> escapeColumnName($this -> toUnderScoreCase($col)) . " $sort ";
					$newPf = " ORDER BY " . $this -> escapeColumnName($col) . " $sort ";
				} else {
					//					$newPf .= ", " . $this -> escapeColumnName($this -> toUnderScoreCase($col)) . " $sort ";
					$newPf .= ", " . $this -> escapeColumnName($col) . " $sort ";
				}
			}
			$sql .= " " . $newPf . $LIMIT;
		} elseif (!empty($postfix)) {
			$sql .= " " . $postfix;
		}
		return $sql;
	}

	function selectLine($tableName, $cols = "*", $where = NULL, $postfix = NULL) {
		if ($postfix === NULL) {
			$postfix = array('#' => '0,1' /* LIMIT 0,1 */
			);
		} elseif (is_array($postfix)) {
			$postfix['#'] = '0,1';
		}
		$sql = $this -> selectSql($tableName, $cols, $where, $postfix);
		return $this -> getLine($sql);
	}

	function selectVar($tableName, $cols = "*", $where = NULL, $postfix = NULL) {
		if ($postfix === NULL) {
			$postfix = array('#' => '0,1' /* LIMIT 0,1 */
			);
		} elseif (is_array($postfix)) {
			$postfix['#'] = '0,1';
		}
		$sql = $this -> selectSql($tableName, $cols, $where, $postfix);
		return $this -> getVar($sql);
	}

	function select($tableName, $cols = "*", $where = NULL, $postfix = NULL, $distinct = FALSE) {
		$sql = $this -> selectSql($tableName, $cols, $where, $postfix, $distinct);
		return $this -> getData($sql);
	}

	/**
	 * 插入记录并返回ID
	 *
	 * @param string $tableName
	 * @param array|object $data
	 * @return number 成功返回true或者id,失败返回false
	 */
	function insertForId($tableName, $data) {
		return insert($tableName, $data, true);
	}

	/**
	 * 插入一条记录
	 *
	 * @param string $tableName
	 * @param array|object $data
	 * @param boolean $needReturnId
	 *        	是否需要返回自增的ID
	 * @return number 成功返回true或者id,失败返回false
	 */
	function insert($tableName, $data, $needReturnId = FALSE) {
		//		if ($this -> underScore === TRUE) {
		//			$tableName = $this -> toUnderScoreCase($tableName);
		//		}
		$sql = "INSERT INTO " . $this -> escapeColumnName($tableName);
		if (is_object($data)) {
			$data = json_decode(json_encode($data), TRUE);
		}
		if (is_array($data)) {
			$keys = '';
			$values = '';

			if ($this -> validateColumnName === true) {
				$table_columns = $this -> get_columns($tableName);
			}
			foreach ($data as $key => $value) {
				//				if ($this -> underScore === TRUE) {
				//					$key = $this -> toUnderScoreCase($key);
				//				}
				if ($this -> validateColumnName === true && array_key_exists($key, $table_columns) === false) {
					// 忽略
					// echo "忽略 $tableName -> $key, \n";
					continue;
				}

				if ($value === TRUE) {
					$value = '1';
				} elseif ($value === FALSE) {
					$value = '0';
				}
				$keys[] = $this -> escapeColumnName($key);
				if ($value === NULL) {
					$values[] = "NULL";
				} else {
					$values[] = "'" . $this -> escape($value) . "'";
				}
			}
			$sql .= " (" . join(",", $keys) . ") VALUES (" . join(",", $values) . ")";
		}

		$ret = $this -> runSql($sql);
		if ($ret === FALSE) {
			return FALSE;
		} elseif ($needReturnId === TRUE && $this -> affectedRows() == 1) {
			return $this -> lastId();
		}
		return TRUE;
	}

	/**
	 * 更新数据
	 *
	 * @param string $tableName
	 * @param array|object $data
	 * @param array|object $where
	 * @return number 失败时返回-1;成功时返回修改的记录数,当数据库中有一模一样的记录时,返回0
	 */
	function update($tableName, $data, $where) {
		//		if ($this -> underScore === TRUE) {
		//			$tableName = $this -> toUnderScoreCase($tableName);
		//		}
		$sql = "UPDATE " . $this -> escapeColumnName($tableName);
		if (is_object($data)) {
			$data = json_decode(json_encode($data), TRUE);
		}

		if (is_array($data)) {
			if ($this -> validateColumnName === true) {
				$table_columns = $this -> get_columns($tableName);
			}
			$pair = array();
			foreach ($data as $key => $value) {
				//				if ($this -> underScore === TRUE) {
				//					$key = $this -> toUnderScoreCase($key);
				//				}
				if ($this -> validateColumnName === true && array_key_exists($key, $table_columns) === false) {
					// 忽略
					continue;
				}

				if ($value === NULL) {
					$pair[] = $this -> escapeColumnName($key) . "=null";
				} elseif ($value === TRUE) {
					$pair[] = $this -> escapeColumnName($key) . "=1";
				} elseif ($value === FALSE) {
					$pair[] = $this -> escapeColumnName($key) . "=0";
				} else {
					if ($value === 0) {
						$value = "0";
					}
					$pair[] = $this -> escapeColumnName($key) . "='" . $this -> escape($value) . "'";
				}
			}
			$sql .= " SET " . join(",", $pair);
		}
		$w = $this -> where($where);
		if ($w) {
			$sql .= " WHERE " . $this -> where($where);
		}
		$ret = $this -> runSql($sql);
		if ($ret === FALSE) {
			return -1;
		}
		$affectedRows = $this -> affectedRows();
		return $affectedRows;
	}

	/**
	 *
	 * @param string $tableName
	 * @param string|object|array $where
	 * @return number 返回被删除的行数,失败返回-1
	 */
	function delete($tableName, $where) {
		//		if ($this -> underScore === TRUE) {
		//			$tableName = $this -> toUnderScoreCase($tableName);
		//		}
		$sql = "DELETE FROM " . $this -> escapeColumnName($tableName);
		$w = $this -> where($where);
		if ($w) {
			$sql .= " WHERE " . $this -> where($where);
		} else {
			// 禁止全表删除
			return -1;
		}
		$ret = $this -> runSql($sql);
		if ($ret === FALSE) {
			return -1;
		}
		$affectedRows = $this -> affectedRows();
		return $affectedRows;
	}

	/**
	 *
	 * @param string|object|array $where
	 * @return string where 后面的语句,不含where
	 */
	public function where($where, $glue = "AND", $ignoreEmptyValue = false) {
		if (empty($where)) {
			return NULL;
		}
		if (is_string($where)) {
			return $where;
		}
		if (is_object($where)) {
			$where = json_decode(json_encode($where), TRUE);
		}
		if (is_array($where)) {
			$pair = array();
			foreach ($where as $key => $value) {
				if ($ignoreEmptyValue === true && ($value === null || $value === "")) {
					continue;
				}

				$operator = null;
				$key_len = strlen($key);
				if ($operator === null && $key_len > 2) {
					$postfix2 = substring($key, -2);
					if ($postfix2 == ">=" || $postfix2 == "<=") {
						$key = substring($key, 0, -2);
						$operator = $postfix2;
					} elseif ($postfix2 == "!=") {
						$key = substring($key, 0, -2);
						$operator = "<>";
					} elseif ($postfix2 == "!@") {
						$key = substring($key, 0, -2);
						$operator = "NOT IN";
					} elseif ($postfix2 == "!~") {
						$key = substring($key, 0, -2);
						$operator = "NOT LIKE";
					} elseif ($postfix2 == "==") {// 双等号改成一个等号
						$key = substring($key, 0, -2);
						$operator = "=";
					}
				}
				if ($operator === null && $key_len > 1) {
					$postfix1 = substring($key, -1);
					if ($postfix1 == ">" || $postfix1 == "<") {
						$key = substring($key, 0, -1);
						$operator = $postfix1;
					} elseif ($postfix1 == "!") {
						$key = substring($key, 0, -1);
						$operator = "<>";
					} elseif ($postfix1 == "~") {// LIKE '%xxx%'
						$key = substring($key, 0, -1);
						$operator = "LIKE";
					} elseif ($postfix1 == "@") {// IN (XX,YYY)
						$key = substring($key, 0, -1);
						$operator = "IN";
					}
				}

				//
				if (empty($operator) && (strtoupper($key) === "AND" || strtoupper($key) === "OR") && (is_object($value) || is_array($value))) {
					$pair[] = "(" . $this -> where($value, strtoupper($key), $ignoreEmptyValue) . ")";
					continue;
				}
				// 禁止加引号,直接用value的值
				if ($key == "*") {
					$pair[] = $value;
					continue;
				}
				if (empty($operator)) {
					$operator = "=";
				}
				if (is_array($value) && array_values($value) === $value) {//数字索引数组
					//					if ($this -> underScore === TRUE) {
					//						// 变成下划线格式
					//						$key = $this -> toUnderScoreCase($key);
					//					}
					if (is_int($value[0]) || is_float($value[0])) {
						if ($operator == "NOT IN" || $operator == "<>") {
							$pair[] = $this -> escapeColumnName($key) . " NOT IN (" . implode(" , ", $value) . ")";
						} else {
							$pair[] = $this -> escapeColumnName($key) . " IN (" . implode(" , ", $value) . ")";
						}
					} else {
						foreach ($value as $i => $v) {
							$value[$i] = $this -> escape($v);
						}
						if ($operator == "NOT IN" || $operator == "<>") {
							$pair[] = $this -> escapeColumnName($key) . " NOT IN ('" . implode("','", $value) . "')";
						} else {
							$pair[] = $this -> escapeColumnName($key) . " IN ('" . implode("','", $value) . "')";
						}
					}
					continue;
				} else {
					//					if ($this -> underScore === TRUE) {
					//						// 变成下划线格式
					//						$key = $this -> toUnderScoreCase($key);
					//					}
					// 把boolean的当作数字看待
					if ($value === TRUE) {
						$value = 1;
					} elseif ($value === FALSE) {
						$value = 0;
					}
					if ($value === NULL) {
						if ($operator === "<>" || $operator === "NOT IN") {
							$pair[] = $this -> escapeColumnName(preg_replace('#[^A-z0-9_\.]#', "", $key)) . " IS NOT NULL ";
						} else {
							$pair[] = $this -> escapeColumnName(preg_replace('#[^A-z0-9_\.]#', "", $key)) . " IS NULL ";
						}
					} elseif (is_float($value) || is_int($value)) {
						if ($value == 0) {
							$value = "0";
						}
						$pair[] = $this -> escapeColumnName($key) . " {$operator} " . $value;
					} elseif (is_numeric($value) && substr($key, 0, 1) === "#") {// 表示该列是数字,不用引号
						if ($value == 0) {
							$value = "0";
						}
						$pair[] = $this -> escapeColumnName(substr($key, 1)) . " {$operator} " . $value;
					} else {
						$pair[] = $this -> escapeColumnName($key) . " {$operator} " . "'" . $this -> escape($value) . "'";
					}
				}
			}
			if (count($pair) > 0) {
				return implode(" {$glue} ", $pair);
			} else {
				return "";
			}
		}
	}

	/**
	 * 判断是否可以用mysql的括号(`)括住(只包含字母数字下划线)
	 */
	function canQuote($name) {
		return preg_match("/^[A-z0-9_]+$/", $name);
	}

	/**
	 * 用mysql的括号(`)括住
	 */
	function escapeColumnName($name) {
		if ($this -> canQuote($name)) {
			return '`' . $name . '`';
		}
		return $name;
	}

	/////////////////////////////////////
	function toCapitalizeWords($str) {
		$words = (preg_replace('/((?<=[a-z|0-9])(?=[A-Z]))/', ' ', $str));
		$words = str_replace("_", " ", $words);
		return ucwords($words);
	}

	/**
	 * 转换字符串成下划线格式(小写):
	 * abcDef=>abc_def
	 * AbcDef=>abc_def
	 *
	 * @param string $str
	 */
	function toUnderScoreCase($str) {
		return strtolower(preg_replace('/((?<=[a-z|0-9])(?=[A-Z]))/', '_', $str));
	}

	/**
	 * 转换字符串成驼峰格式(小写开头):
	 * abc_def=>abcDef
	 *
	 * @param string $str
	 */
	function toCamelCase($str) {
		$ret = toCapitalizeCamelCase($str);
		return lcfirst($ret);
	}

	/**
	 * 转换字符串成驼峰格式(大写开头):
	 * abc_def=>abcDef
	 *
	 * @param string $str
	 */
	function toCapitalizeCamelCase($str) {
		$ret = preg_replace("/(?:^|_)([a-z])/e", "strtoupper('\\1')", $str);
		return str_replace("_", "", $ret);
	}

}

// echo substring ( "sdfasdf1*<", - 1 );
