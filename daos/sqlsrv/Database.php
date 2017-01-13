<?php

namespace daos\sqlsrv;

/**
 * Base class for database access -- sqlsrv.
 *
 * @copyright Copyright (c) Tobias Zeising (http://www.aditu.de)
 * @license   GPLv3 (https://www.gnu.org/licenses/gpl-3.0.html)
 * @author    Tobias Zeising <tobias.zeising@aditu.de>
 */
class Database {
    /**
     * indicates whether database connection was initialized.
     *
     * @var bool
     */
    private static $initialized = false;

    /**
     * establish connection and create undefined tables.
     */
    public function __construct() {
        if (self::$initialized === false && \F3::get('db_type') === 'sqlsrv') {
            $host = \F3::get('db_host');
            $port = \F3::get('db_port');
            $database = \F3::get('db_database');

            if ($port) {
                $dsn = "sqlsrv:Server=$host, $port; Database=$database";
            } else {
                $dsn = "sqlsrv:Server=$host; Database=$database";
            }

            \F3::get('logger')->log('Establish database connection', \DEBUG);
            \F3::set('db', new \DB\SQL($dsn, \F3::get('db_username'), \F3::get('db_password')));

            // create tables if necessary
            $result = @\F3::get('db')->exec("SELECT [TABLE_NAME] FROM [INFORMATION_SCHEMA].[TABLES] WHERE [TABLE_TYPE] = 'BASE TABLE' AND [TABLE_CATALOG] = ?", $database);
            $tables = array();
            foreach ($result as $table) {
                $tables[] = $table['TABLE_NAME'];
            }

            if (!in_array('items', $tables)) {
                \F3::get('db')->exec('
                    CREATE TABLE dbo.' . \F3::get('db_prefix') . 'items
                    (
                       id int NOT NULL IDENTITY PRIMARY KEY,
                       datetime datetime2(0) NOT NULL,
                       title varchar(max) NOT NULL,
                       content varchar(max) NOT NULL,
                       thumbnail varchar(max) NULL,
                       icon varchar(max) NULL,
                       unread bit NOT NULL,
                       starred bit NOT NULL,
                       source int NOT NULL,
                       uid varchar(255) NOT NULL,
                       link varchar(max) NOT NULL,
                       updatetime datetime2(0) NOT NULL DEFAULT getdate(),
                       author varchar(255) NULL
                    )
                ');

                \F3::get('db')->exec('
                    CREATE NONCLUSTERED INDEX ncsource
                       ON dbo.' . \F3::get('db_prefix') . 'items (source ASC)
                ');
                \F3::get('db')->exec('
                    CREATE TRIGGER update_updatetime_trigger
                        ON items
                        AFTER UPDATE
                        AS
                        BEGIN
                              UPDATE ' . \F3::get('db_prefix') . 'items
                              SET ' . \F3::get('db_prefix') . 'items.updatetime = GETDATE()
                        end
                 ');
            }

            $isNewestSourcesTable = false;
            if (!in_array('sources', $tables)) {
                \F3::get('db')->exec('
                    CREATE TABLE dbo.sources
                    (
                       id int NOT NULL IDENTITY PRIMARY KEY,
                       title nvarchar(max) NOT NULL,
                       tags nvarchar(max) NULL,
                       spout nvarchar(max) NOT NULL,
                       params nvarchar(max) NOT NULL,
                       filter nvarchar(max) NULL,
                       error nvarchar(max) NULL,
                       lastupdate int NULL,
                       lastentry int NULL
                    )
                ');
                $isNewestSourcesTable = true;
            }

            // version 1
            if (!in_array('version', $tables)) {
                \F3::get('db')->exec('CREATE TABLE version (version INT);');

                \F3::get('db')->exec('INSERT INTO version (version) VALUES (8);');

                \F3::get('db')->exec('
                    CREATE TABLE tags (
                        tag         TEXT NOT NULL,
                        color       TEXT NOT NULL
                    );
                ');

                if ($isNewestSourcesTable === false) {
                    \F3::get('db')->exec('ALTER TABLE sources ADD tags TEXT;');
                }
            } else {
                $version = @\F3::get('db')->exec('SELECT top 1 version FROM version ORDER BY version DESC');
                $version = $version[0]['version'];

                if (strnatcmp($version, '3') < 0) {
                    \F3::get('db')->exec('ALTER TABLE sources ADD lastupdate INT;');
                    \F3::get('db')->exec('INSERT INTO version (version) VALUES (3);');
                }
                if (strnatcmp($version, '4') < 0) {
                    \F3::get('db')->exec('ALTER TABLE items ADD updatetime DATETIME;');
                    \F3::get('db')->exec('
                        CREATE TRIGGER insert_updatetime_trigger
                        AFTER INSERT ON items FOR EACH ROW
                            BEGIN
                                UPDATE items
                                SET updatetime = CURRENT_TIMESTAMP
                                WHERE id = NEW.id;
                            END;
                    ');
                    \F3::get('db')->exec('
                        CREATE TRIGGER update_updatetime_trigger
                        AFTER UPDATE ON items FOR EACH ROW
                            BEGIN
                                UPDATE items
                                SET updatetime = CURRENT_TIMESTAMP
                                WHERE id = NEW.id;
                            END;
                    ');
                    \F3::get('db')->exec('
                        INSERT INTO version (version) VALUES (4);
                    ');
                }
                if (strnatcmp($version, '5') < 0) {
                    \F3::get('db')->exec('
                        ALTER TABLE items ADD author VARCHAR(255);
                    ');
                    \F3::get('db')->exec('
                        INSERT INTO version (version) VALUES (5);
                    ');
                }
                if (strnatcmp($version, '6') < 0) {
                    \F3::get('db')->exec('
                        ALTER TABLE sources ADD filter TEXT;
                    ');
                    \F3::get('db')->exec('
                        INSERT INTO version (version) VALUES (6);
                    ');
                }
                // Jump straight from v6 to v8 due to bug in previous version of the code
                // in /daos/sqlsrv/Database.php which
                // set the database version to "7" for initial installs.
                if (strnatcmp($version, '8') < 0) {
                    \F3::get('db')->exec('
                        ALTER TABLE sources ADD lastentry INT;
                    ');
                    \F3::get('db')->exec('
                        INSERT INTO version (version) VALUES (8);
                    ');

                    $this->initLastEntryFieldDuringUpgrade();
                }
                if (strnatcmp($version, '9') < 0) {
                    \F3::get('db')->exec('
                        ALTER TABLE ' . \F3::get('db_prefix') . 'items ADD shared bit;
                    ');
                    \F3::get('db')->exec('
                        INSERT INTO version (version) VALUES (9);
                    ');
                }
            }

            // just initialize once
            self::$initialized = true;
        }
    }

    /**
     * optimize database by database own optimize statement.
     */
    public function optimize() {
        /*@\F3::get('db')->exec('
            VACUUM;
        ');*/
    }

    /**
     * Initialize 'lastentry' Field in Source table during database upgrade.
     */
    private function initLastEntryFieldDuringUpgrade() {
        $sources = @\F3::get('db')->exec('SELECT id FROM sources');

        // have a look at each entry in the source table
        foreach ($sources as $current_src) {
            //get the date of the newest entry found in the database
            $latestEntryDate = @\F3::get('db')->exec('SELECT top 1 datetime FROM items WHERE source=?  ORDER BY datetime DESC', $current_src['id']);

            //if an entry for this source was found in the database, write the date of the newest one into the sources table
            if (isset($latestEntryDate[0]['datetime'])) {
                @\F3::get('db')->exec('UPDATE sources SET lastentry=? WHERE id=?', strtotime($latestEntryDate[0]['datetime']), $current_src['id']);
            }
        }
    }
}
