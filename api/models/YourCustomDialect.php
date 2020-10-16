<?php
class YourCustomDialect extends \Phalcon\Db\Dialect\PostgreSQL implements \Phalcon\Db\DialectInterface {

    /**
     * Generates SQL checking for the existence of a schema.view
     */
    public function viewExists($viewName, $schemaName = null) {
        if ($schemaName) {
            return "SELECT CASE WHEN COUNT(*) > 0 THEN 1 ELSE 0 END FROM pg_class c INNER JOIN pg_namespace n ON c.relnamespace = n.oid WHERE relkind IN ('v', 'm') AND relname='" . $viewName . "' AND n.nspname = '" . $schemaName . "'";
        }
        return "SELECT CASE WHEN COUNT(*) > 0 THEN 1 ELSE 0 END FROM pg_class c WHERE relkind IN ('v', 'm') AND relname='" . $viewName . "'";
    }

    /**
     * Generates the SQL to list all views of a schema or user
     *
     * @param string schemaName
     * @return string
     */
    public function listViews($schemaName = null) {
        if ($schemaName) {
            return "SELECT relname AS view_name FROM pg_class c INNER JOIN pg_namespace n ON c.relnamespace = n.oid WHERE relkind IN ('v', 'm') AND n.nspname = '" . $schemaName . "' ORDER BY view_name";
        }
        return "SELECT relname AS view_name FROM pg_class c INNER JOIN pg_namespace n ON c.relnamespace = n.oid WHERE relkind IN ('v', 'm') AND n.nspname = 'public' ORDER BY view_name";
    }

    /**
     * Generates SQL checking for the existence of a schema.table
     *
     * <code>
     *    echo $dialect->tableExists("posts", "blog");
     *    echo $dialect->tableExists("posts");
     * </code>
     */
    public function tableExists($tableName, $schemaName = null) {
        if ($schemaName) {
            return "SELECT CASE WHEN COUNT(*) > 0 THEN 1 ELSE 0 END FROM pg_class c INNER JOIN pg_namespace n ON c.relnamespace = n.oid WHERE relkind IN ('v', 'm', 'r') AND relname='" . $tableName . "' AND n.nspname = '" . $schemaName . "'";
        }
        return "SELECT CASE WHEN COUNT(*) > 0 THEN 1 ELSE 0 END FROM pg_class c WHERE relkind IN ('v', 'm', 'r') AND relname='" . $tableName . "'";
    }
}

?>