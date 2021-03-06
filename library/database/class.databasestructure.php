<?php
/**
 * Database Structure tools
 *
 * @author Todd Burry <todd@vanillaforums.com>
 * @copyright 2009-2016 Vanilla Forums Inc.
 * @license http://www.opensource.org/licenses/gpl-2.0.php GNU GPL v2
 * @package Core
 * @since 2.0
 */

/**
 * Used by any given database driver to build, modify, and create tables and views.
 */
abstract class Gdn_DatabaseStructure extends Gdn_Pluggable {

    /** @var string  */
    protected $_DatabasePrefix = '';

    /**
     * @var bool Whether or not to only capture the sql, rather than execute it. When this property is true
     * then a property called CapturedSql will be added to this class which is an array of all the Sql statements.
     */
    public $CaptureOnly = false;

    /** @var string The character encoding to set as default for the table being created. */
    protected $_CharacterEncoding;

    /** @var array $ColumnName => $ColumnPropertiesObject columns to be added to $this->_TableName. */
    protected $_Columns;

    /** @var Gdn_Database The instance of the database singleton. */
    public $Database;

    /** @var array The existing columns in the database. */
    protected $_ExistingColumns = null;

    /** @var string The name of the table to create or modify. */
    protected $_TableName;

    /** @var bool Whether or not this table exists in the database. */
    protected $_TableExists;

    /** @var string The name of the storage engine for this table. */
    protected $_TableStorageEngine;

    /**
     * The constructor for this class. Automatically fills $this->ClassName.
     *
     * @param string $Database
     * @todo $Database needs a description.
     */
    public function __construct($Database = null) {
        parent::__construct();

        if (is_null($Database)) {
            $this->Database = Gdn::database();
        } else {
            $this->Database = $Database;
        }

        $this->databasePrefix($this->Database->DatabasePrefix);

        $this->reset();
    }

    /**
     *
     *
     * @param $Name
     * @param $Type
     * @param $Null
     * @param $Default
     * @param $KeyType
     * @return stdClass
     */
    protected function _createColumn($Name, $Type, $Null, $Default, $KeyType) {
        $Length = '';
        $Precision = '';

        // Check to see if the type starts with a 'u' for unsigned.
        if (is_string($Type) && strncasecmp($Type, 'u', 1) == 0) {
            $Type = substr($Type, 1);
            $Unsigned = true;
        } else {
            $Unsigned = false;
        }

        // Check for a length in the type.
        if (is_string($Type) && preg_match('/(\w+)\s*\(\s*(\d+)\s*(?:,\s*(\d+)\s*)?\)/', $Type, $Matches)) {
            $Type = $Matches[1];
            $Length = $Matches[2];
            if (count($Matches) >= 4) {
                $Precision = $Matches[3];
            }
        }

        $Column = new stdClass();
        $Column->Name = $Name;
        $Column->Type = is_array($Type) ? 'enum' : $Type;
        $Column->Length = $Length;
        $Column->Precision = $Precision;
        $Column->Enum = is_array($Type) ? $Type : false;
        $Column->AllowNull = $Null;
        $Column->Default = $Default;
        $Column->KeyType = $KeyType;
        $Column->Unsigned = $Unsigned;
        $Column->AutoIncrement = false;

        // Handle enums and sets as types.
        if (is_array($Type)) {
            if (count($Type) === 2 && is_array(val(1, $Type))) {
                // The type is specified as the first element in the array.
                $Column->Type = $Type[0];
                $Column->Enum = $Type[1];
            } else {
                // This is an enum.
                $Column->Type = 'enum';
                $Column->Enum = $Type;
            }
        } else {
            $Column->Type = $Type;
            $Column->Enum = false;
        }

        return $Column;
    }

    /**
     * Defines a column to be added to $this->Table().
     *
     * @param string $Name The name of the column to create.
     * @param mixed $Type The data type of the column to be created. Types with a length speecifty the length in barackets.
     * * If an array of values is provided, the type will be set as "enum" and the array will be assigned as the column's Enum property.
     * * If an array of two values is specified then a "set" or "enum" can be specified (ex. array('set', array('Short', 'Tall', 'Fat', 'Skinny')))
     * @param boolean $NullDefault Whether or not nulls are allowed, if not a default can be specified.
     * * TRUE: Nulls are allowed.
     * * FALSE: Nulls are not allowed.
     * * Any other value: Nulls are not allowed, and the specified value will be used as the default.
     * @param string $KeyType What type of key is this column on the table? Options
     * are primary, key, and FALSE (not a key).
     */
    public function column($Name, $Type, $NullDefault = false, $KeyType = false) {
        if (is_null($NullDefault) || $NullDefault === true) {
            $Null = true;
            $Default = null;
        } elseif ($NullDefault === false) {
            $Null = false;
            $Default = null;
        } elseif (is_array($NullDefault)) {
            $Null = val('Null', $NullDefault);
            $Default = val('Default', $NullDefault, null);
        } else {
            $Null = false;
            $Default = $NullDefault;
        }

        // Check the key type for validity. A column can be in many keys by specifying an array as key type.
        $KeyTypes = (array)$KeyType;
        $KeyTypes1 = array();
        foreach ($KeyTypes as $KeyType1) {
            $Parts = explode('.', $KeyType1, 2);

            if (in_array($Parts[0], array('primary', 'key', 'index', 'unique', 'fulltext', false))) {
                $KeyTypes1[] = $KeyType1;
            }
        }
        if (count($KeyTypes1) == 0) {
            $KeyType = false;
        } elseif (count($KeyTypes1) == 1)
            $KeyType = $KeyTypes1[0];
        else {
            $KeyType = $KeyTypes1;
        }

        $Column = $this->_createColumn($Name, $Type, $Null, $Default, $KeyType);
        $this->_Columns[$Name] = $Column;
        return $this;
    }

    /**
     * Returns whether or not a column exists in the database.
     *
     * @param string $ColumnName The name of the column to check.
     * @return bool
     */
    public function columnExists($ColumnName) {
        $Result = array_key_exists($ColumnName, $this->existingColumns());
        if (!$Result) {
            foreach ($this->existingColumns() as $ColName => $Def) {
                if (strcasecmp($ColumnName, $ColName) == 0) {
                    return true;
                }
            }
            return false;
        }
        return $Result;
    }

    /**
     * An associative array of $ColumnName => $ColumnProperties columns for the table.
     *
     * @return array
     */
    public function columns($Name = '') {
        if (strlen($Name) > 0) {
            if (array_key_exists($Name, $this->_Columns)) {
                return $this->_Columns[$Name];
            } else {
                foreach ($this->_Columns as $ColName => $Def) {
                    if (strcasecmp($Name, $ColName) == 0) {
                        return $Def;
                    }
                }
                return null;
            }
        }
        return $this->_Columns;
    }

    /**
     * Return the definition string for a column.
     *
     * @param mixed $Column The column to get the type string from.
     *  - <b>object</b>: The column as returned by the database schema. The properties looked at are Type, Length, and Precision.
     *  - <b>string</b<: The name of the column currently in this structure.
     * @return string The type definition string.
     */
    public function columnTypeString($Column) {
        if (is_string($Column)) {
            $Column = $this->_Columns[$Column];
        }

        $Type = val('Type', $Column);
        $Length = val('Length', $Column);
        $Precision = val('Precision', $Column);

        if (in_array(strtolower($Type), array('tinyint', 'smallint', 'mediumint', 'int', 'float', 'double'))) {
            $Length = null;
        }

        if ($Type && $Length && $Precision) {
            $Result = "$Type($Length, $Precision)";
        } elseif ($Type && $Length)
            $Result = "$Type($Length)";
        elseif (strtolower($Type) == 'enum') {
            $Result = val('Enum', $Column, array());
        } elseif ($Type)
            $Result = $Type;
        else {
            $Result = 'int';
        }

        return $Result;
    }

    /**
     * Gets and/or sets the database prefix.
     *
     * @param string $DatabasePrefix
     * @todo $DatabasePrefix needs a description.
     */
    public function databasePrefix($DatabasePrefix = '') {
        if ($DatabasePrefix != '') {
            $this->_DatabasePrefix = $DatabasePrefix;
        }

        return $this->_DatabasePrefix;
    }

    /**
     * Drops $this->Table() from the database.
     */
    public function drop() {
        trigger_error(errorMessage('The selected database engine does not perform the requested task.', $this->ClassName, 'Drop'), E_USER_ERROR);
    }

    /**
     * Drops $Name column from $this->Table().
     *
     * @param string $Name The name of the column to drop from $this->Table().
     */
    public function dropColumn($Name) {
        trigger_error(errorMessage('The selected database engine does not perform the requested task.', $this->ClassName, 'DropColumn'), E_USER_ERROR);
    }

    /**
     *
     *
     * @param $Engine
     * @param bool $CheckAvailability
     */
    public function engine($Engine, $CheckAvailability = true) {
        trigger_error(errorMessage('The selected database engine does not perform the requested task.', $this->ClassName, 'Engine'), E_USER_ERROR);
    }


    /**
     * Load the schema for this table from the database.
     *
     * @param string $TableName The name of the table to get or blank to get the schema for the current table.
     * @return Gdn_DatabaseStructure $this
     */
    public function get($TableName = '') {
        if ($TableName) {
            $this->table($TableName);
        }

        $Columns = $this->Database->sql()->fetchTableSchema($this->_TableName);
        $this->_Columns = $Columns;

        return $this;
    }

    /**
     * Defines a primary key column on a table.
     *
     * @param string $Name The name of the column.
     * @param string $Type The data type of the column.
     * @return Gdn_DatabaseStructure $this.
     */
    public function primaryKey($Name, $Type = 'int') {
        $Column = $this->_createColumn($Name, $Type, false, null, 'primary');
        $Column->AutoIncrement = true;
        $this->_Columns[$Name] = $Column;

        return $this;
    }

    /**
     * Send a query to the database and return the result.
     *
     * @param string $Sql The sql to execute.
     * @return bool Whethor or not the query succeeded.
     */
    public function query($Sql) {
        if ($this->CaptureOnly) {
            if (!property_exists($this->Database, 'CapturedSql')) {
                $this->Database->CapturedSql = array();
            }
            $this->Database->CapturedSql[] = $Sql;
            return true;
        } else {
            $Result = $this->Database->query($Sql);
            return $Result;
        }
    }

    /**
     * Renames a column in $this->Table().
     *
     * @param string $OldName The name of the column to be renamed.
     * @param string $NewName The new name for the column being renamed.
     */
    public function renameColumn($OldName, $NewName) {
        trigger_error(errorMessage('The selected database engine does not perform the requested task.', $this->ClassName, 'RenameColumn'), E_USER_ERROR);
    }

    /**
     * Renames a table in the database.
     *
     * @param string $OldName The name of the table to be renamed.
     * @param string $NewName The new name for the table being renamed.
     * @param boolean $UsePrefix A boolean value indicating if $this->_DatabasePrefix should be prefixed
     * before $OldName and $NewName.
     */
    public function renameTable($OldName, $NewName, $UsePrefix = false) {
        trigger_error(errorMessage('The selected database engine does not perform the requested task.', $this->ClassName, 'RenameTable'), E_USER_ERROR);
    }

    /**
     * Creates the table and columns specified with $this->Table() and
     * $this->Column(). If no table or columns have been specified, this method
     * will throw a fatal error.
     *
     * @param boolean $Explicit If TRUE, and the table specified with $this->Table() already exists, this
     * method will remove any columns from the table that were not defined with
     * $this->Column().
     * @param boolean $Drop If TRUE, and the table specified with $this->Table() already exists, this
     * method will drop the table before attempting to re-create it.
     */
    public function set($Explicit = false, $Drop = false) {
        /// Throw an event so that the structure can be overridden.
        $this->EventArguments['Explicit'] = $Explicit;
        $this->EventArguments['Drop'] = $Drop;
        $this->fireEvent('BeforeSet');

        try {
            // Make sure that table and columns have been defined
            if ($this->_TableName == '') {
                throw new Exception(T('You must specify a table before calling DatabaseStructure::Set()'));
            }

            if (count($this->_Columns) == 0) {
                throw new Exception(T('You must provide at least one column before calling DatabaseStructure::Set()'));
            }

            if ($this->tableExists()) {
                if ($Drop) {
                    // Drop the table.
                    $this->drop();

                    // And re-create it.
                    return $this->_create();
                }

                // If the table already exists, go into modify mode.
                return $this->_modify($Explicit, $Drop);
            } else {
                // If it doesn't already exist, go into create mode.
                return $this->_create();
            }
        } catch (Exception $Ex) {
            $this->reset();
            throw $Ex;
        }
    }

    /**
     * Specifies the name of the table to create or modify.
     *
     * @param string $Name The name of the table.
     * @param string $CharacterEncoding The default character encoding to specify for this table.
     */
    public function table($Name = '', $CharacterEncoding = '') {
        if (!$Name) {
            return $this->_TableName;
        }

        $this->_TableName = $Name;
        if ($CharacterEncoding == '') {
            $CharacterEncoding = Gdn::config('Database.CharacterEncoding', '');
        }

        $this->_CharacterEncoding = $CharacterEncoding;
        return $this;
    }

    /**
     * Whether or not the table exists in the database.
     *
     * @return bool
     */
    public function tableExists($TableName = null) {
        if ($this->_TableExists === null || $TableName !== null) {
            if ($TableName === null) {
                $TableName = $this->tableName();
            }

            if (strlen($TableName) > 0) {
                $Tables = $this->Database->sql()->fetchTables(':_'.$TableName);
                $Result = count($Tables) > 0;
            } else {
                $Result = false;
            }
            if ($TableName == $this->tableName()) {
                $this->_TableExists = $Result;
            }
            return $Result;
        }
        return $this->_TableExists;
    }

    /**
     * Returns the name of the table being defined in this object.
     *
     * @return string
     */
    public function tableName() {
        return $this->_TableName;
    }

    /**
     * Gets an array of type names allowed in the structure.
     *
     * @param string $Class The class of types to get. Valid values are:
     *  - <b>int</b>: Integer types.
     *  - <b>float</b>: Floating point types.
     *  - <b>decimal</b>: Precise decimal types.
     *  - <b>numeric</b>: float, int and decimal.
     *  - <b>string</b>: String types.
     *  - <b>date</b>: Date types.
     *  - <b>length</b>: Types that have a length.
     *  - <b>precision</b>: Types that have a precision.
     *  - <b>other</b>: Types that don't fit into any other category on their own.
     *  - <b>all</b>: All recognized types.
     */
    public function types($Class = 'all') {
        $Date = array('datetime', 'date');
        $Decimal = array('decimal', 'numeric');
        $Float = array('float', 'double');
        $Int = array('int', 'tinyint', 'smallint', 'mediumint', 'bigint');
        $String = array('varchar', 'char', 'mediumtext', 'text');
        $Length = array('varbinary');
        $Other = array('enum', 'tinyblob', 'blob', 'mediumblob', 'longblob', 'ipaddress');

        switch (strtolower($Class)) {
            case 'date':
                return $Date;
            case 'decimal':
                return $Decimal;
            case 'float':
                return $Float;
            case 'int':
                return $Int;
            case 'string':
                return $String;
            case 'other':
                return array_merge($Length, $Other);

            case 'numeric':
                return array_merge($Float, $Int, $Decimal);
            case 'length':
                return array_merge($String, $Length, $Decimal);
            case 'precision':
                return $Decimal;
            default:
                return array();
        }
    }

    /**
     * Specifies the name of the view to create or modify.
     *
     * @param string $Name The name of the view.
     * @param string $Query Query to create as the view. Typically this can be generated with the $Database object.
     */
    public function view($Name, $Query) {
        trigger_error(errorMessage('The selected database engine can not create or modify views.', $this->ClassName, 'View'), E_USER_ERROR);
    }

    /**
     * Creates the table defined with $this->Table() and $this->Column().
     */
    protected function _create() {
        trigger_error(errorMessage('The selected database engine does not perform the requested task.', $this->ClassName, '_Create'), E_USER_ERROR);
    }

    /**
     * Gets the column definitions for the columns in the database.
     *
     * @return array
     */
    public function existingColumns() {
        if ($this->_ExistingColumns === null) {
            if ($this->TableExists()) {
                $this->_ExistingColumns = $this->Database->sql()->fetchTableSchema($this->_TableName);
            } else {
                $this->_ExistingColumns = array();
            }
        }
        return $this->_ExistingColumns;
    }

    /**
     * Modifies $this->Table() with the columns specified with $this->Column().
     *
     * @param boolean $Explicit If TRUE, this method will remove any columns from the table that were not
     * defined with $this->Column().
     */
    protected function _modify($Explicit = false) {
        trigger_error(errorMessage('The selected database engine does not perform the requested task.', $this->ClassName, '_Modify'), E_USER_ERROR);
    }

    /**
     * Reset the internal state of this object so that it can be reused.
     *
     * @return Gdn_DatabaseStructure $this
     */
    public function reset() {
        $this->_CharacterEncoding = '';
        $this->_Columns = array();
        $this->_ExistingColumns = null;
        $this->_TableExists = null;
        $this->_TableName = '';
        $this->_TableStorageEngine = null;

        return $this;
    }
}
