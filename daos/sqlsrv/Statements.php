<?php

namespace daos\sqlsrv;

/**
 * sqlsrv specific statements.
 *
 * @copyright Copyright (c) Tobias Zeising (http://www.aditu.de)
 * @license   GPLv3 (https://www.gnu.org/licenses/gpl-3.0.html)
 * @author    Tobias Zeising <tobias.zeising@aditu.de>
 */
class Statements extends \daos\mysql\Statements {
    /**
     * wrap insert statement to return id.
     *
     * @param sql statement
     * @param sql params
     *
     * @return id after insert
     */
    public static function insert($query, $params) {
        \F3::get('db')->exec($query, $params);
        $res = \F3::get('db')->exec('SELECT last_insert_rowid() as lastid');

        return $res[0]['lastid'];
    }

    /**
     * check if CSV column matches a value.
     *
     * @param CSV column to check
     * @param value to search in CSV column
     *
     * @return full statement
     */
    public static function csvRowMatches($column, $value) {
        return "(',' || $column || ',') LIKE ('%,' || $value || ',%')";
    }
}
