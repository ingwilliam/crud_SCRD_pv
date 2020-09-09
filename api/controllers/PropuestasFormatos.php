<?php

//error_reporting(E_ALL);
//ini_set('display_errors', '1');
use Phalcon\Loader;
use Phalcon\Mvc\Micro;
use Phalcon\Di\FactoryDefault;
use Phalcon\Db\Adapter\Pdo\Postgresql as DbAdapter;
use Phalcon\Config\Adapter\Ini as ConfigIni;
use Phalcon\Http\Request;
use Phalcon\Logger\Adapter\File as FileAdapter;
use Phalcon\Logger\Formatter\Line;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\IOFactory;

// Definimos algunas rutas constantes para localizar recursos
define('BASE_PATH', dirname(__DIR__));
define('APP_PATH', BASE_PATH);

//Defino las variables principales de conexion
$config = new ConfigIni('../config/config.ini');

// Registramos un autoloader
$loader = new Loader();

$loader->registerDirs(
        [
            APP_PATH . '/models/',
            APP_PATH . '/library/class/',
        ]
);

$loader->register();

// Crear un DI
$di = new FactoryDefault();

//Set up the database service
$di->set('db', function () use ($config) {
    return new DbAdapter(
            array(
        "host" => $config->database->host, "port" => $config->database->port,
        "username" => $config->database->username,
        "password" => $config->database->password,
        "dbname" => $config->database->name
            )
    );
});

//Funcionalidad para crear los log de la aplicación
//la carpeta debe tener la propietario y usuario
//sudo chown -R www-data:www-data log/
//https://docs.phalcon.io/3.4/es-es/logging
$formatter = new Line('{"date":"%date%","type":"%type%",%message%},');
$formatter->setDateFormat('Y-m-d H:i:s');
$logger = new FileAdapter($config->sistema->path_log . "convocatorias." . date("Y-m-d") . ".log");
$logger->setFormatter($formatter);

$app = new Micro($di);

$app->post('/ejemplo_xls', function () use ($app, $config, $logger) {
    //Instancio los objetos que se van a manejar
    $request = new Request();
    $tokens = new Tokens();

    try {

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPut('token'));

        //Registro la accion en el log de convocatorias
        $logger->info('"token":"{token}","user":"{user}","message":"Ingresa al metodo reporte_listado_entidades_convocatorias_estado_xls para generar reporte de listado de inscripcion de la propuesta (' . $request->getPut('id') . ')"', ['user' => '', 'token' => $request->getPut('token')]);

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {


            require_once("../library/phpspreadsheet/autoload.php");

            $entidad = Entidades::findFirst($request->getPut('entidad'));
            $anio = $request->getPut('anio');

            //Genero reporte propuestas por estado
            $sql_convocatorias = "
                SELECT c.convocatoria,c.categoria,es.nombre AS estado,COUNT(p.id) AS total FROM Viewconvocatorias AS c
                INNER JOIN Propuestas AS p ON p.convocatoria= c.id_categoria
                INNER JOIN Estados AS es ON es.id= p.estado
                WHERE c.modalidad<>2 AND c.anio='" . $anio . "' AND c.entidad=" . $entidad->id . "
                GROUP BY 1,2,3
                ORDER BY 1,2,3,4";

            $convocatorias = $app->modelsManager->executeQuery($sql_convocatorias);

            $documento = new Spreadsheet();
            $documento
                    ->getProperties()
                    ->setCreator("SICON")
                    ->setLastModifiedBy('SICON') // última vez modificado por
                    ->setTitle('Estado de propuestas')
                    ->setSubject('SICON')
                    ->setDescription('Estado de propuestas')
                    ->setKeywords('SICON')
                    ->setCategory('La categoría');

            $hoja = $documento->getActiveSheet();
            $hoja->setTitle("Estado de propuestas");

            //Cabezote de la tabla
            $hoja->setCellValueByColumnAndRow(1, 1, "Estado de propuestas");

            //Cabezote de la tabla
            $hoja->setCellValueByColumnAndRow(1, 2, "Fecha de corte");
            $hoja->setCellValueByColumnAndRow(2, 2, date("Y-m-d H:i:s"));

            //Cabezote de la tabla
            $hoja->setCellValueByColumnAndRow(1, 3, "Año");
            $hoja->setCellValueByColumnAndRow(2, 3, $anio);
            $hoja->setCellValueByColumnAndRow(3, 3, "Entidad");
            $hoja->setCellValueByColumnAndRow(4, 3, $entidad->descripcion);

            //Cabezote de la tabla
            $hoja->setCellValueByColumnAndRow(1, 5, "Convocatoria");
            $hoja->setCellValueByColumnAndRow(2, 5, "Categoría");
            $hoja->setCellValueByColumnAndRow(3, 5, "Estado de la propuesta");
            $hoja->setCellValueByColumnAndRow(4, 5, "Total");

            //Registros de la base de datos
            $fila = 6;
            foreach ($convocatorias as $convocatoria) {
                $hoja->setCellValueByColumnAndRow(1, $fila, $convocatoria->convocatoria);
                $hoja->setCellValueByColumnAndRow(2, $fila, $convocatoria->categoria);
                $hoja->setCellValueByColumnAndRow(3, $fila, $convocatoria->estado);
                $hoja->setCellValueByColumnAndRow(4, $fila, $convocatoria->total);
                $fila++;
            }


            $nombreDelDocumento = "listado_entidades_convocatorias_estado_" . $entidad->id . "_" . $anio . ".xlsx";

            // Redirect output to a client’s web browser (Xlsx)
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment;filename="' . $nombreDelDocumento . '"');
            header('Cache-Control: max-age=0');
            // If you're serving to IE 9, then the following may be needed
            header('Cache-Control: max-age=1');

            // If you're serving to IE over SSL, then the following may be needed
            header('Expires: Mon, 26 Jul 1997 05:00:00 GMT'); // Date in the past
            header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT'); // always modified
            header('Cache-Control: cache, must-revalidate'); // HTTP/1.1
            header('Pragma: public'); // HTTP/1.0
            # Le pasamos la ruta de guardado
            $writer = IOFactory::createWriter($documento, "Xlsx"); //Xls is also possible
            $writer->save('php://output');
        } else {
            //Registro la accion en el log de convocatorias           
            $logger->error('"token":"{token}","user":"{user}","message":"Token caduco en el metodo reporte_listado_entidades_convocatorias_estado_xls al generar el reporte listado de la propuesta (' . $request->getPut('id') . ')', ['user' => "", 'token' => $request->getPut('token')]);
            $logger->close();
            echo "error_token";
        }
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias           
        $logger->error('"token":"{token}","user":"{user}","message":"Error metodo reporte_listado_entidades_convocatorias_estado_xls al generar el reporte listado de la propuesta (' . $request->getPut('id') . ')' . $ex->getMessage() . '"', ['user' => "", 'token' => $request->getPut('token')]);
        $logger->close();
        echo "error_metodo";
    }
});

$app->post('/propuesta_presupuesto_xls', function () use ($app, $config, $logger) {
    //Instancio los objetos que se van a manejar
    $request = new Request();
    $tokens = new Tokens();

    try {

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPut('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {
            
            //Usuario actual
            $user_current = json_decode($token_actual->user_current, true);
            
            //Registro la accion en el log de convocatorias
            $logger->info('"token":"{token}","user":"{user}","message":"Ingresa al controlador PropuestasFormatos en el método propuesta_presupuesto_xls, ingreso a generar el presupuesto de la propuesta (' . $request->getPut('id') . ')"', ['user' => $user_current['username'], 'token' => $request->getPut('token')]);


            require_once("../library/phpspreadsheet/autoload.php");

            $documento = new Spreadsheet();
            $documento
                    ->getProperties()
                    ->setCreator("SICON")
                    ->setLastModifiedBy('SICON') // última vez modificado por
                    ->setTitle('Estado de propuestas')
                    ->setSubject('SICON')
                    ->setDescription('Estado de propuestas')
                    ->setKeywords('SICON')
                    ->setCategory('La categoría');

            $hoja = $documento->getActiveSheet();
            $hoja->setTitle("Estado de propuestas");
            


            $nombreDelDocumento = "nombre_del_archivo_descargar.xlsx";

            // Redirect output to a client’s web browser (Xlsx)
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment;filename="' . $nombreDelDocumento . '"');
            header('Cache-Control: max-age=0');
            // If you're serving to IE 9, then the following may be needed
            header('Cache-Control: max-age=1');

            // If you're serving to IE over SSL, then the following may be needed
            header('Expires: Mon, 26 Jul 1997 05:00:00 GMT'); // Date in the past
            header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT'); // always modified
            header('Cache-Control: cache, must-revalidate'); // HTTP/1.1
            header('Pragma: public'); // HTTP/1.0
            # Le pasamos la ruta de guardado
            $writer = IOFactory::createWriter($documento, "Xlsx"); //Xls is also possible
            $writer->save('php://output');
            
            //Registro la accion en el log de convocatorias
            $logger->info('"token":"{token}","user":"{user}","message":"Retorno en el controlador PropuestasFormatos en el método propuesta_presupuesto_xls, retorno el reporte del presupuesto de la propuesta (' . $request->getPut('id') . ')"', ['user' => $user_current['username'], 'token' => $request->getPut('token')]);
            
        } else {
            //Registro la accion en el log de convocatorias           
            $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador PropuestasFormatos en el método propuesta_presupuesto_xls, token caduco en la propuesta (' . $request->getPut('id') . ')', ['user' => "", 'token' => $request->getPut('token')]);
            $logger->close();
            echo "error_token";
        }
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias           
        $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador PropuestasFormatos en el método propuesta_presupuesto_xls, error metodo en la propuesta (' . $request->getPut('id') . ')' . $ex->getMessage() . '"', ['user' => "", 'token' => $request->getPut('token')]);
        $logger->close();
        echo "error_metodo";
    }
});

try {
    // Gestionar la consulta
    $app->handle();
} catch (\Exception $e) {
    echo 'Excepción: ', $e->getMessage();
}
?>