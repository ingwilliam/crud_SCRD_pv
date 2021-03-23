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

$app->post('/reporte_listado_entidades_convocatorias_estado_xls', function () use ($app, $config, $logger) {
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

$app->post('/reporte_listado_entidades_convocatorias_estado', function () use ($app, $config, $logger) {

//Instancio los objetos que se van a manejar
    $request = new Request();
    $tokens = new Tokens();

    try {

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPut('token'));

        //Registro la accion en el log de convocatorias
        $logger->info('"token":"{token}","user":"{user}","message":"Ingresa al metodo reporte_listado_entidades_convocatorias_estado para generar reporte de listado de inscripcion de la propuesta (' . $request->getPut('id') . ')"', ['user' => '', 'token' => $request->getPut('token')]);

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

            //Consulto lo necesario
            $user_current = json_decode($token_actual->user_current, true);
            $entidad = Entidades::findFirst($request->getPut('entidad'));

            //Genero reporte propuestas por estado
            $sql_convocatorias = "
                SELECT c.convocatoria,c.categoria,es.nombre AS estado,COUNT(p.id) AS total FROM Viewconvocatorias AS c
                INNER JOIN Propuestas AS p ON p.convocatoria= c.id_categoria
                INNER JOIN Estados AS es ON es.id= p.estado
                WHERE c.modalidad<>2 AND c.anio='" . $request->getPut('anio') . "' AND c.entidad=" . $request->getPut('entidad') . "
                GROUP BY 1,2,3
                ORDER BY 1,2,3,4";

            $convocatorias = $app->modelsManager->executeQuery($sql_convocatorias);

            $html_propuestas = "";
            foreach ($convocatorias as $convocatoria) {
                $html_propuestas = $html_propuestas . "<tr>";
                $html_propuestas = $html_propuestas . "<td>" . $convocatoria->convocatoria . "</td>";
                $html_propuestas = $html_propuestas . "<td>" . $convocatoria->categoria . "</td>";
                $html_propuestas = $html_propuestas . "<td>" . $convocatoria->estado . "</td>";
                $html_propuestas = $html_propuestas . "<td>" . $convocatoria->total . "</td>";
                $html_propuestas = $html_propuestas . "</tr>";
            }

            $html = '<table border="1" cellpadding="2" cellspacing="2" nobr="true">
                    <tr>
                        <td colspan="4" align="center">Estado de propuestas</td>
                    </tr>
                    <tr>
                        <td colspan="4" align="center"> Fecha de corte ' . date("Y-m-d H:i:s") . '</td>
                    </tr>
                    <tr>
                        <td colspan="2">Año: ' . $request->getPut('anio') . '</td>
                        <td colspan="2">Entidad: ' . $entidad->descripcion . '</td>
                    </tr>                                    
                    <tr style="background-color:#BDBDBD;color:#OOOOOO;">
                        <td align="center">Convocatoria</td>
                        <td align="center">Categoría</td>
                        <td align="center">Estado de la propuesta</td>
                        <td align="center">Total</td>                        
                    </tr> 
                    ' . $html_propuestas . '
                </table>';

            $logger->info('"token":"{token}","user":"{user}","message":"Se genero el reporte de inscripcion de la propuesta (' . $request->getPut('id') . ')', ['user' => $user_current["username"], 'token' => $request->getPut('token')]);
            $logger->close();
            echo $html;
        } else {
            //Registro la accion en el log de convocatorias           
            $logger->error('"token":"{token}","user":"{user}","message":"Token caduco en el metodo reporte_listado_entidades_convocatorias_estado al generar el reporte listado de la propuesta (' . $request->getPut('id') . ')', ['user' => "", 'token' => $request->getPut('token')]);
            $logger->close();
            echo "error_token";
        }
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias           
        $logger->error('"token":"{token}","user":"{user}","message":"Error metodo reporte_listado_entidades_convocatorias_estado al generar el reporte listado de la propuesta (' . $request->getPut('id') . ')' . $ex->getMessage() . '"', ['user' => "", 'token' => $request->getPut('token')]);
        $logger->close();
        echo "error_metodo";
    }
});


/*
 * 13-10-2020
 * Wilmer GUstavo Mogollón Duque
 * Se incorpora método reporte_linea_base_jurados_xls
 */


$app->post('/reporte_linea_base_jurados_xls', function () use ($app, $config, $logger) {

    //Instancio los objetos que se van a manejar
    $request = new Request();
    $tokens = new Tokens();

    try {
        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPut('token'));

        //Registro la accion en el log de convocatorias
        $logger->info('"token":"{token}","user":"{user}","message":"Ingresa al metodo reporte_linea_base_jurados_xls para generar reporte de listado de inscripcion de la propuesta (' . $request->getPut('id') . ')"', ['user' => '', 'token' => $request->getPut('token')]);

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

            //Le permito mas memoria a la accion
            ini_set('memory_limit', '-1');


            require_once("../library/phpspreadsheet/autoload.php");

            $entidad = Entidades::findFirst($request->getPut('entidad'));

            $anio = $request->getPut('anio');


            //Genero reporte invocando a la vista
            $sql_convocatorias = "SELECT * from Viewlineabasejuradosgenerals as vlb WHERE vlb.anio = " . $anio . " AND vlb.entidad_id = " . $entidad->id . " LIMIT 10";

            $convocatorias = $app->modelsManager->executeQuery($sql_convocatorias);

            $documento = new Spreadsheet();
            $documento
                    ->getProperties()
                    ->setCreator("SICON")
                    ->setLastModifiedBy('SICON') // última vez modificado por
                    ->setTitle('Listado de jurados por convocatoria')
                    ->setSubject('SICON')
                    ->setDescription('Listado de jurados por convocatoria')
                    ->setKeywords('SICON')
                    ->setCategory('La categoría');

            $hoja = $documento->getActiveSheet();
            $hoja->setTitle("Jurados por convocatoria");

            //Cabezote de la tabla
            $hoja->setCellValueByColumnAndRow(1, 1, "Listado de jurados por convocatoria");

            //Cabezote de la tabla
            $hoja->setCellValueByColumnAndRow(1, 2, "Fecha de corte");
            $hoja->setCellValueByColumnAndRow(2, 2, date("Y-m-d H:i:s"));

            //Cabezote de la tabla
            $hoja->setCellValueByColumnAndRow(1, 3, "Año");
            $hoja->setCellValueByColumnAndRow(2, 3, $anio);
            $hoja->setCellValueByColumnAndRow(3, 3, "Entidad");
            $hoja->setCellValueByColumnAndRow(4, 3, $entidad->descripcion);

            //Cabezote de la tabla
//            $hoja->setCellValueByColumnAndRow(1, 5, "Entidad");
            $hoja->setCellValueByColumnAndRow(1, 5, "Modalidad participa");
            $hoja->setCellValueByColumnAndRow(2, 5, "Entidad");
            $hoja->setCellValueByColumnAndRow(3, 5, "Convocatoria");
            $hoja->setCellValueByColumnAndRow(4, 5, "Convocatoria padre");
            $hoja->setCellValueByColumnAndRow(5, 5, "Diferentes categorias");
            $hoja->setCellValueByColumnAndRow(6, 5, "Año");
            $hoja->setCellValueByColumnAndRow(7, 5, "Tipo");
            $hoja->setCellValueByColumnAndRow(8, 5, "CC");
            $hoja->setCellValueByColumnAndRow(9, 5, "Código hoja de vida");
            $hoja->setCellValueByColumnAndRow(10, 5, "Primer nombre");
            $hoja->setCellValueByColumnAndRow(11, 5, "Segundo nombre");
            $hoja->setCellValueByColumnAndRow(12, 5, "Primer apellido");
            $hoja->setCellValueByColumnAndRow(13, 5, "Segundo apellido");
            $hoja->setCellValueByColumnAndRow(14, 5, "Fecha nacimiento");
            $hoja->setCellValueByColumnAndRow(15, 5, "Edad");
            $hoja->setCellValueByColumnAndRow(16, 5, "Étnia");
            $hoja->setCellValueByColumnAndRow(17, 5, "Género");
            $hoja->setCellValueByColumnAndRow(18, 5, "Dirección de residencia");
            $hoja->setCellValueByColumnAndRow(19, 5, "Ciudad de residencia");
            $hoja->setCellValueByColumnAndRow(20, 5, "Localidad");
            $hoja->setCellValueByColumnAndRow(21, 5, "UPZ");
            $hoja->setCellValueByColumnAndRow(22, 5, "Barrio de residencia");
            $hoja->setCellValueByColumnAndRow(23, 5, "Estrato");
            $hoja->setCellValueByColumnAndRow(24, 5, "Celular");
            $hoja->setCellValueByColumnAndRow(25, 5, "Teléfono fijo");
            $hoja->setCellValueByColumnAndRow(26, 5, "Correo electrónico");
            $hoja->setCellValueByColumnAndRow(27, 5, "Estado de la propuesta");
            $hoja->setCellValueByColumnAndRow(28, 5, "Estado de la postulación");
            $hoja->setCellValueByColumnAndRow(29, 5, "Monto asignado");
            $hoja->setCellValueByColumnAndRow(30, 5, "Número resolución");
            $hoja->setCellValueByColumnAndRow(31, 5, "Fecha resolución");
            $hoja->setCellValueByColumnAndRow(32, 5, "Código presupuestal");
            $hoja->setCellValueByColumnAndRow(33, 5, "Código proyecto de inversión");
            $hoja->setCellValueByColumnAndRow(34, 5, "CDP");
            $hoja->setCellValueByColumnAndRow(35, 5, "CRP");
            $fila = 6;

            foreach ($convocatorias as $convocatoria) {


                $hoja->setCellValueByColumnAndRow(1, $fila, $convocatoria->modalidad_participa);
                $hoja->setCellValueByColumnAndRow(2, $fila, $convocatoria->entidad);
                $hoja->setCellValueByColumnAndRow(3, $fila, $convocatoria->convocatoria);
                $hoja->setCellValueByColumnAndRow(4, $fila, $convocatoria->convocatoria_padre);
                $hoja->setCellValueByColumnAndRow(5, $fila, $convocatoria->diferentes_cat);
                $hoja->setCellValueByColumnAndRow(6, $fila, $convocatoria->anio);
                $hoja->setCellValueByColumnAndRow(7, $fila, $convocatoria->tipo_documento);
                $hoja->setCellValueByColumnAndRow(8, $fila, $convocatoria->numero_documento);
                $hoja->setCellValueByColumnAndRow(9, $fila, $convocatoria->codigo);
                $hoja->setCellValueByColumnAndRow(10, $fila, $convocatoria->primer_nombre);
                $hoja->setCellValueByColumnAndRow(11, $fila, $convocatoria->segundo_nombre);
                $hoja->setCellValueByColumnAndRow(12, $fila, $convocatoria->primer_apellido);
                $hoja->setCellValueByColumnAndRow(13, $fila, $convocatoria->segundo_apellido);
                $hoja->setCellValueByColumnAndRow(14, $fila, $convocatoria->fecha_nacimiento);
                $hoja->setCellValueByColumnAndRow(15, $fila, $convocatoria->anios);
                $hoja->setCellValueByColumnAndRow(16, $fila, $convocatoria->etnia);
                $hoja->setCellValueByColumnAndRow(17, $fila, $convocatoria->genero);
                $hoja->setCellValueByColumnAndRow(18, $fila, $convocatoria->direccion_residencia);
                $hoja->setCellValueByColumnAndRow(19, $fila, $convocatoria->ciudad_residencia);
                $hoja->setCellValueByColumnAndRow(20, $fila, $convocatoria->localidad_residencia);
                $hoja->setCellValueByColumnAndRow(21, $fila, $convocatoria->upz_residencia);
                $hoja->setCellValueByColumnAndRow(22, $fila, $convocatoria->barrio_residencia);
                $hoja->setCellValueByColumnAndRow(23, $fila, $convocatoria->estrato);
                $hoja->setCellValueByColumnAndRow(24, $fila, $convocatoria->numero_celular);
                $hoja->setCellValueByColumnAndRow(25, $fila, $convocatoria->numero_telefono);
                $hoja->setCellValueByColumnAndRow(26, $fila, $convocatoria->correo_electronico);
                $hoja->setCellValueByColumnAndRow(27, $fila, $convocatoria->estado_propuesta);
                $hoja->setCellValueByColumnAndRow(28, $fila, $convocatoria->estado_postulacion);
                $hoja->setCellValueByColumnAndRow(29, $fila, $convocatoria->monto_asignado);
                $hoja->setCellValueByColumnAndRow(30, $fila, $convocatoria->numero_resolucion);
                $hoja->setCellValueByColumnAndRow(31, $fila, $convocatoria->fecha_resolucion);
                $hoja->setCellValueByColumnAndRow(32, $fila, $convocatoria->codigo_presupuestal);
                $hoja->setCellValueByColumnAndRow(33, $fila, $convocatoria->codigo_proyecto_inversion);
                $hoja->setCellValueByColumnAndRow(34, $fila, $convocatoria->crp);
                $hoja->setCellValueByColumnAndRow(35, $fila, $convocatoria->cdp);
                $fila++;
            }


            $nombreDelDocumento = "linea_base_jurados_" . $entidad->id . "_" . $anio . ".xlsx";

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
            $logger->error('"token":"{token}","user":"{user}","message":"Token caduco en el metodo reporte_listado_entidades_convocatorias_listado_jurados_xls al generar el reporte listado de la propuesta (' . $request->getPut('id') . ')', ['user' => "", 'token' => $request->getPut('token')]);
            $logger->close();
            echo "error_token";
        }
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias           
        $logger->error('"token":"{token}","user":"{user}","message":"Error metodo reporte_listado_entidades_convocatorias_listado_jurados_xls al generar el reporte listado de la propuesta (' . $request->getPut('id') . ')' . $ex->getMessage() . '"', ['user' => "", 'token' => $request->getPut('token')]);
        $logger->close();
        echo "error_metodo";
    }
});


/*
 * 14-10-2020
 * Wilmer Gustavo Mogollón Duque
 * Se incorpora acción en el controlador para generar reporte de linea base de jurados general a partir de una vista
 */

$app->post('/reporte_linea_base_jurados_general_xls', function () use ($app, $config, $logger) {

    //Instancio los objetos que se van a manejar
    $request = new Request();
    $tokens = new Tokens();

    try {
        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPut('token'));

        //Registro la accion en el log de convocatorias
        $logger->info('"token":"{token}","user":"{user}","message":"Ingresa al metodo reporte_linea_base_jurados_general_xls para generar reporte de listado de inscripcion de la propuesta (' . $request->getPut('id') . ')"', ['user' => '', 'token' => $request->getPut('token')]);

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

            //Le permito mas memoria a la accion
            ini_set('memory_limit', '-1');


            require_once("../library/phpspreadsheet/autoload.php");

//            $entidad = Entidades::findFirst($request->getPut('entidad'));
            $anio = $request->getPut('anio');


            //Genero reporte invocando a la vista
            $sql_convocatorias = "SELECT * from Viewlineabasejuradosgenerals as vlb WHERE vlb.anio=" . $anio . " LIMIT 10";


            $convocatorias = $app->modelsManager->executeQuery($sql_convocatorias);


            $documento = new Spreadsheet();
            $documento
                    ->getProperties()
                    ->setCreator("SICON")
                    ->setLastModifiedBy('SICON') // última vez modificado por
                    ->setTitle('Listado de jurados por convocatoria')
                    ->setSubject('SICON')
                    ->setDescription('Listado de jurados por convocatoria')
                    ->setKeywords('SICON')
                    ->setCategory('La categoría');

            $hoja = $documento->getActiveSheet();
            $hoja->setTitle("Línea base de jurados general");

            //Cabezote de la tabla
            $hoja->setCellValueByColumnAndRow(1, 1, "Línea base de jurados general");

            //Cabezote de la tabla
            $hoja->setCellValueByColumnAndRow(1, 2, "Fecha de corte");
            $hoja->setCellValueByColumnAndRow(2, 2, date("Y-m-d H:i:s"));

            //Cabezote de la tabla
            $hoja->setCellValueByColumnAndRow(1, 3, "Año");
            $hoja->setCellValueByColumnAndRow(2, 3, $anio);

            //Cabezote de la tabla
//            $hoja->setCellValueByColumnAndRow(1, 5, "Entidad");
            $hoja->setCellValueByColumnAndRow(1, 5, "Modalidad participa");
            $hoja->setCellValueByColumnAndRow(2, 5, "Entidad");
            $hoja->setCellValueByColumnAndRow(3, 5, "Convocatoria");
            $hoja->setCellValueByColumnAndRow(4, 5, "Convocatoria padre");
            $hoja->setCellValueByColumnAndRow(5, 5, "Diferentes categorias");
            $hoja->setCellValueByColumnAndRow(6, 5, "Año");
            $hoja->setCellValueByColumnAndRow(7, 5, "Tipo");
            $hoja->setCellValueByColumnAndRow(8, 5, "CC");
            $hoja->setCellValueByColumnAndRow(9, 5, "Código hoja de vida");
            $hoja->setCellValueByColumnAndRow(10, 5, "Primer nombre");
            $hoja->setCellValueByColumnAndRow(11, 5, "Segundo nombre");
            $hoja->setCellValueByColumnAndRow(12, 5, "Primer apellido");
            $hoja->setCellValueByColumnAndRow(13, 5, "Segundo apellido");
            $hoja->setCellValueByColumnAndRow(14, 5, "Fecha nacimiento");
            $hoja->setCellValueByColumnAndRow(15, 5, "Edad");
            $hoja->setCellValueByColumnAndRow(16, 5, "Étnia");
            $hoja->setCellValueByColumnAndRow(17, 5, "Género");
            $hoja->setCellValueByColumnAndRow(18, 5, "Dirección de residencia");
            $hoja->setCellValueByColumnAndRow(19, 5, "Ciudad de residencia");
            $hoja->setCellValueByColumnAndRow(20, 5, "Localidad");
            $hoja->setCellValueByColumnAndRow(21, 5, "UPZ");
            $hoja->setCellValueByColumnAndRow(22, 5, "Barrio de residencia");
            $hoja->setCellValueByColumnAndRow(23, 5, "Estrato");
            $hoja->setCellValueByColumnAndRow(24, 5, "Celular");
            $hoja->setCellValueByColumnAndRow(25, 5, "Teléfono fijo");
            $hoja->setCellValueByColumnAndRow(26, 5, "Correo electrónico");
            $hoja->setCellValueByColumnAndRow(27, 5, "Estado de la propuesta");
            $hoja->setCellValueByColumnAndRow(28, 5, "Estado de la postulación");
            $hoja->setCellValueByColumnAndRow(29, 5, "Monto asignado");
            $hoja->setCellValueByColumnAndRow(30, 5, "Número resolución");
            $hoja->setCellValueByColumnAndRow(31, 5, "Fecha resolución");
            $hoja->setCellValueByColumnAndRow(32, 5, "Código presupuestal");
            $hoja->setCellValueByColumnAndRow(33, 5, "Código proyecto de inversión");
            $hoja->setCellValueByColumnAndRow(34, 5, "CDP");
            $hoja->setCellValueByColumnAndRow(35, 5, "CRP");
            $fila = 6;


            foreach ($convocatorias as $convocatoria) {



                $hoja->setCellValueByColumnAndRow(1, $fila, $convocatoria->modalidad_participa);
                $hoja->setCellValueByColumnAndRow(2, $fila, $convocatoria->entidad);
                $hoja->setCellValueByColumnAndRow(3, $fila, $convocatoria->convocatoria);
                $hoja->setCellValueByColumnAndRow(4, $fila, $convocatoria->convocatoria_padre);
                $hoja->setCellValueByColumnAndRow(5, $fila, $convocatoria->diferentes_cat);
                $hoja->setCellValueByColumnAndRow(6, $fila, $convocatoria->anio);
                $hoja->setCellValueByColumnAndRow(7, $fila, $convocatoria->tipo_documento);
                $hoja->setCellValueByColumnAndRow(8, $fila, $convocatoria->numero_documento);
                $hoja->setCellValueByColumnAndRow(9, $fila, $convocatoria->codigo);
                $hoja->setCellValueByColumnAndRow(10, $fila, $convocatoria->primer_nombre);
                $hoja->setCellValueByColumnAndRow(11, $fila, $convocatoria->segundo_nombre);
                $hoja->setCellValueByColumnAndRow(12, $fila, $convocatoria->primer_apellido);
                $hoja->setCellValueByColumnAndRow(13, $fila, $convocatoria->segundo_apellido);
                $hoja->setCellValueByColumnAndRow(14, $fila, $convocatoria->fecha_nacimiento);
                $hoja->setCellValueByColumnAndRow(15, $fila, $convocatoria->anios);
                $hoja->setCellValueByColumnAndRow(16, $fila, $convocatoria->etnia);
                $hoja->setCellValueByColumnAndRow(17, $fila, $convocatoria->genero);
                $hoja->setCellValueByColumnAndRow(18, $fila, $convocatoria->direccion_residencia);
                $hoja->setCellValueByColumnAndRow(19, $fila, $convocatoria->ciudad_residencia);
                $hoja->setCellValueByColumnAndRow(20, $fila, $convocatoria->localidad_residencia);
                $hoja->setCellValueByColumnAndRow(21, $fila, $convocatoria->upz_residencia);
                $hoja->setCellValueByColumnAndRow(22, $fila, $convocatoria->barrio_residencia);
                $hoja->setCellValueByColumnAndRow(23, $fila, $convocatoria->estrato);
                $hoja->setCellValueByColumnAndRow(24, $fila, $convocatoria->numero_celular);
                $hoja->setCellValueByColumnAndRow(25, $fila, $convocatoria->numero_telefono);
                $hoja->setCellValueByColumnAndRow(26, $fila, $convocatoria->correo_electronico);
                $hoja->setCellValueByColumnAndRow(27, $fila, $convocatoria->estado_propuesta);
                $hoja->setCellValueByColumnAndRow(28, $fila, $convocatoria->estado_postulacion);
                $hoja->setCellValueByColumnAndRow(29, $fila, $convocatoria->monto_asignado);
                $hoja->setCellValueByColumnAndRow(30, $fila, $convocatoria->numero_resolucion);
                $hoja->setCellValueByColumnAndRow(31, $fila, $convocatoria->fecha_resolucion);
                $hoja->setCellValueByColumnAndRow(32, $fila, $convocatoria->codigo_presupuestal);
                $hoja->setCellValueByColumnAndRow(33, $fila, $convocatoria->codigo_proyecto_inversion);
                $hoja->setCellValueByColumnAndRow(34, $fila, $convocatoria->crp);
                $hoja->setCellValueByColumnAndRow(35, $fila, $convocatoria->cdp);

                $fila++;
            }


            $nombreDelDocumento = "linea_base_jurados_" . $anio . ".xlsx";

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
            $logger->error('"token":"{token}","user":"{user}","message":"Token caduco en el metodo reporte_listado_entidades_convocatorias_listado_jurados_xls al generar el reporte listado de la propuesta (' . $request->getPut('id') . ')', ['user' => "", 'token' => $request->getPut('token')]);
            $logger->close();
            echo "error_token";
        }
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias           
        $logger->error('"token":"{token}","user":"{user}","message":"Error metodo reporte_listado_entidades_convocatorias_listado_jurados_xls al generar el reporte listado de la propuesta (' . $request->getPut('id') . ')' . $ex->getMessage() . '"', ['user' => "", 'token' => $request->getPut('token')]);
        $logger->close();
        echo "error_metodo";
    }
});


/*
 * 14-10-2020
 * Wilmer Gustavo Mogollón Duque
 * Se incorpora acción en el controlador para generar reporte de linea base de convocatorias a partir de una vista
 */

$app->post('/reporte_linea_base_convocatorias_xls', function () use ($app, $config, $logger) {

    //Instancio los objetos que se van a manejar
    $request = new Request();
    $tokens = new Tokens();

    try {
        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPut('token'));

        //Registro la accion en el log de convocatorias
        $logger->info('"token":"{token}","user":"{user}","message":"Ingresa al metodo reporte_linea_base_convocatorias_xls para generar línea base de convocatorias (' . $request->getPut('id') . ')"', ['user' => '', 'token' => $request->getPut('token')]);

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

            //Le permito mas memoria a la accion
            ini_set('memory_limit', '-1');


            require_once("../library/phpspreadsheet/autoload.php");

//            $entidad = Entidades::findFirst($request->getPut('entidad'));
            $anio = $request->getPut('anio');


            //Genero reporte invocando a la vista
            $sql_convocatorias = "SELECT * from Viewlineasbases as vlb WHERE vlb.anio=" . $anio . " LIMIT 10";


            $convocatorias = $app->modelsManager->executeQuery($sql_convocatorias);


            $documento = new Spreadsheet();
            $documento
                    ->getProperties()
                    ->setCreator("SICON")
                    ->setLastModifiedBy('SICON') // última vez modificado por
                    ->setTitle('Línea base de convocatorias')
                    ->setSubject('SICON')
                    ->setDescription('Listado de jurados por convocatoria')
                    ->setKeywords('SICON')
                    ->setCategory('La categoría');

            $hoja = $documento->getActiveSheet();
            $hoja->setTitle("Línea base de convocatorias");

            //Cabezote de la tabla
            $hoja->setCellValueByColumnAndRow(1, 1, "Línea base de convocatorias");

            //Cabezote de la tabla
            $hoja->setCellValueByColumnAndRow(1, 2, "Fecha de corte");
            $hoja->setCellValueByColumnAndRow(2, 2, date("Y-m-d H:i:s"));

            //Cabezote de la tabla
            $hoja->setCellValueByColumnAndRow(1, 3, "Año");
            $hoja->setCellValueByColumnAndRow(2, 3, $anio);

            //Cabezote de la tabla
//            $hoja->setCellValueByColumnAndRow(1, 5, "Entidad");
            $hoja->setCellValueByColumnAndRow(1, 5, "Entidad");
            $hoja->setCellValueByColumnAndRow(2, 5, "Estado");
            $hoja->setCellValueByColumnAndRow(3, 5, "Fecha cierre");
            $hoja->setCellValueByColumnAndRow(4, 5, "Convocatoria");
            $hoja->setCellValueByColumnAndRow(5, 5, "Categoria");
            $hoja->setCellValueByColumnAndRow(6, 5, "Area");
            $hoja->setCellValueByColumnAndRow(7, 5, "Línea estratégica");
            $hoja->setCellValueByColumnAndRow(8, 5, "Enfoque");
            $hoja->setCellValueByColumnAndRow(9, 5, "Estado de la propuesta");
            $hoja->setCellValueByColumnAndRow(10, 5, "Código");
            $hoja->setCellValueByColumnAndRow(11, 5, "Nombre de la propuesta");
            $hoja->setCellValueByColumnAndRow(12, 5, "Localidad ejecución propuesta");
            $hoja->setCellValueByColumnAndRow(13, 5, "UPZ ejecución propuesta");
            $hoja->setCellValueByColumnAndRow(14, 5, "Barrio ejecución propuesta");
            $hoja->setCellValueByColumnAndRow(15, 5, "Tipo participante");
            $hoja->setCellValueByColumnAndRow(16, 5, "Representante");
            $hoja->setCellValueByColumnAndRow(17, 5, "Tipo rol");
            $hoja->setCellValueByColumnAndRow(18, 5, "Rol");
            $hoja->setCellValueByColumnAndRow(19, 5, "Número documento");
            $hoja->setCellValueByColumnAndRow(20, 5, "Primer nombre");
            $hoja->setCellValueByColumnAndRow(21, 5, "Segundo nombre");
            $hoja->setCellValueByColumnAndRow(22, 5, "Primer apellido");
            $hoja->setCellValueByColumnAndRow(23, 5, "Segundo apellido");
            $hoja->setCellValueByColumnAndRow(24, 5, "Id tipo doc");
            $hoja->setCellValueByColumnAndRow(25, 5, "Tipo doc");
            $hoja->setCellValueByColumnAndRow(26, 5, "Fecha nacimiento");
            $hoja->setCellValueByColumnAndRow(27, 5, "Sexo");
            $hoja->setCellValueByColumnAndRow(28, 5, "Dirección residencia");
            $hoja->setCellValueByColumnAndRow(29, 5, "Ciudad residencia");
            $hoja->setCellValueByColumnAndRow(30, 5, "Localidad residencia");
            $hoja->setCellValueByColumnAndRow(31, 5, "UPZ residencia");
            $hoja->setCellValueByColumnAndRow(32, 5, "Barrio residencia");
            $hoja->setCellValueByColumnAndRow(33, 5, "Estrato");
            $hoja->setCellValueByColumnAndRow(34, 5, "Correo electrónico");
            $hoja->setCellValueByColumnAndRow(35, 5, "Número teléfono");
            $hoja->setCellValueByColumnAndRow(36, 5, "Número celular");
            $hoja->setCellValueByColumnAndRow(37, 5, "Año");
            $fila = 6;


            foreach ($convocatorias as $convocatoria) {

                $hoja->setCellValueByColumnAndRow(1, $fila, $convocatoria->nombre_entidad);
                $hoja->setCellValueByColumnAndRow(2, $fila, $convocatoria->estado);
                $hoja->setCellValueByColumnAndRow(3, $fila, $convocatoria->fecha_cierre);
                $hoja->setCellValueByColumnAndRow(4, $fila, $convocatoria->convocatoria);
                $hoja->setCellValueByColumnAndRow(5, $fila, $convocatoria->categoria);
                $hoja->setCellValueByColumnAndRow(6, $fila, $convocatoria->area);
                $hoja->setCellValueByColumnAndRow(7, $fila, $convocatoria->linea_estrategica);
                $hoja->setCellValueByColumnAndRow(8, $fila, $convocatoria->enfoque);
                $hoja->setCellValueByColumnAndRow(9, $fila, $convocatoria->estado_propuesta);
                $hoja->setCellValueByColumnAndRow(10, $fila, $convocatoria->codigo);
                $hoja->setCellValueByColumnAndRow(11, $fila, $convocatoria->nombre_propuesta);
                $hoja->setCellValueByColumnAndRow(12, $fila, $convocatoria->localidad_ejecucion_propuesta);
                $hoja->setCellValueByColumnAndRow(13, $fila, $convocatoria->upz_ejecucion_propuesta);
                $hoja->setCellValueByColumnAndRow(14, $fila, $convocatoria->barrio_ejecucion_propuesta);
                $hoja->setCellValueByColumnAndRow(15, $fila, $convocatoria->tipo_participante);
                $hoja->setCellValueByColumnAndRow(16, $fila, $convocatoria->representante);
                $hoja->setCellValueByColumnAndRow(17, $fila, $convocatoria->tipo_rol);
                $hoja->setCellValueByColumnAndRow(18, $fila, $convocatoria->rol);
                $hoja->setCellValueByColumnAndRow(19, $fila, $convocatoria->numero_documento);
                $hoja->setCellValueByColumnAndRow(20, $fila, $convocatoria->primer_nombre);
                $hoja->setCellValueByColumnAndRow(21, $fila, $convocatoria->segundo_nombre);
                $hoja->setCellValueByColumnAndRow(22, $fila, $convocatoria->primer_apellido);
                $hoja->setCellValueByColumnAndRow(23, $fila, $convocatoria->segundo_apellido);
                $hoja->setCellValueByColumnAndRow(24, $fila, $convocatoria->id_tipo_documento);
                $hoja->setCellValueByColumnAndRow(25, $fila, $convocatoria->tipo_documento);
                $hoja->setCellValueByColumnAndRow(26, $fila, $convocatoria->fecha_nacimiento);
                $hoja->setCellValueByColumnAndRow(27, $fila, $convocatoria->sexo);
                $hoja->setCellValueByColumnAndRow(28, $fila, $convocatoria->direccion_residencia);
                $hoja->setCellValueByColumnAndRow(29, $fila, $convocatoria->ciudad_residencia);
                $hoja->setCellValueByColumnAndRow(30, $fila, $convocatoria->localidad_residencia);
                $hoja->setCellValueByColumnAndRow(31, $fila, $convocatoria->upz_residencia);
                $hoja->setCellValueByColumnAndRow(32, $fila, $convocatoria->barrio_residencia);
                $hoja->setCellValueByColumnAndRow(33, $fila, $convocatoria->estrato);
                $hoja->setCellValueByColumnAndRow(34, $fila, $convocatoria->correo_electronico);
                $hoja->setCellValueByColumnAndRow(35, $fila, $convocatoria->numero_telefono);
                $hoja->setCellValueByColumnAndRow(36, $fila, $convocatoria->numero_celular);
                $hoja->setCellValueByColumnAndRow(37, $fila, $convocatoria->anio);

                $fila++;
            }


            $nombreDelDocumento = "linea_base_convocatorias_" . $anio . ".xlsx";

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
            $logger->error('"token":"{token}","user":"{user}","message":"Token caduco en el metodo reporte_linea_base_convocatorias_xls al generar el reporte listado de la propuesta (' . $request->getPut('id') . ')', ['user' => "", 'token' => $request->getPut('token')]);
            $logger->close();
            echo "error_token";
        }
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias           
        $logger->error('"token":"{token}","user":"{user}","message":"Error metodo reporte_linea_base_convocatorias_xls al generar el reporte listado de la propuesta (' . $request->getPut('id') . ')' . $ex->getMessage() . '"', ['user' => "", 'token' => $request->getPut('token')]);
        $logger->close();
        echo "error_metodo";
    }
});



$app->post('/reporte_convocatorias_cerrar_xls', function () use ($app, $config, $logger) {

    //Instancio los objetos que se van a manejar
    $request = new Request();
    $tokens = new Tokens();

    try {
        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPut('token'));

        //Registro la accion en el log de convocatorias
        $logger->info('"token":"{token}","user":"{user}","message":"Ingresa al metodo reporte_convocatorias_cerrar_xls para generar reporte de listado de inscripcion de la propuesta (' . $request->getPut('id') . ')"', ['user' => '', 'token' => $request->getPut('token')]);

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {


            require_once("../library/phpspreadsheet/autoload.php");

            $entidad = Entidades::findFirst($request->getPut('entidad'));
            $anio = $request->getPut('anio');

            //Genero reporte propuestas por estado
            $sql_convocatorias = "
            SELECT vc.convocatoria,vc.categoria,cc.fecha_fin FROM Viewconvocatorias AS vc
            INNER JOIN Convocatoriascronogramas AS cc ON cc.convocatoria=vc.id_diferente
            WHERE vc.anio='" . $anio . "' AND cc.tipo_evento=12 AND vc.entidad='" . $entidad->id . "'
            ORDER BY cc.fecha_inicio, vc.convocatoria";

            $convocatorias = $app->modelsManager->executeQuery($sql_convocatorias);

            $documento = new Spreadsheet();
            $documento
                    ->getProperties()
                    ->setCreator("SICON")
                    ->setLastModifiedBy('SICON') // última vez modificado por
                    ->setTitle('Convocatorias próximas a cerrar')
                    ->setSubject('SICON')
                    ->setDescription('Convocatorias próximas a cerrar')
                    ->setKeywords('SICON')
                    ->setCategory('La categoría');

            $hoja = $documento->getActiveSheet();
            $hoja->setTitle("Convocatorias próximas a cerrar");

            //Cabezote de la tabla
            $hoja->setCellValueByColumnAndRow(1, 1, "Convocatorias próximas a cerrar");

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
            $hoja->setCellValueByColumnAndRow(3, 5, "Fecha de cierre");

            //Registros de la base de datos
            $fila = 6;
            foreach ($convocatorias as $convocatoria) {
                $hoja->setCellValueByColumnAndRow(1, $fila, $convocatoria->convocatoria);
                $hoja->setCellValueByColumnAndRow(2, $fila, $convocatoria->categoria);
                $hoja->setCellValueByColumnAndRow(3, $fila, $convocatoria->fecha_fin);
                $fila++;
            }


            $nombreDelDocumento = "listado_entidades_convocatorias_cerrar_" . $entidad->id . "_" . $anio . ".xlsx";

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
            $logger->error('"token":"{token}","user":"{user}","message":"Token caduco en el metodo reporte_convocatorias_cerrar_xls al generar el reporte listado de la propuesta (' . $request->getPut('id') . ')', ['user' => "", 'token' => $request->getPut('token')]);
            $logger->close();
            echo "error_token";
        }
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias           
        $logger->error('"token":"{token}","user":"{user}","message":"Error metodo reporte_convocatorias_cerrar_xls al generar el reporte listado de la propuesta (' . $request->getPut('id') . ')' . $ex->getMessage() . '"', ['user' => "", 'token' => $request->getPut('token')]);
        $logger->close();
        echo "error_metodo";
    }
});

$app->post('/reporte_listado_entidades_convocatorias_cerrar', function () use ($app, $config, $logger) {

//Instancio los objetos que se van a manejar
    $request = new Request();
    $tokens = new Tokens();

    try {

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPut('token'));

        //Registro la accion en el log de convocatorias
        $logger->info('"token":"{token}","user":"{user}","message":"Ingresa al metodo reporte_listado_entidades_convocatorias_cerrar para generar reporte de listado de inscripcion de la propuesta (' . $request->getPut('id') . ')"', ['user' => '', 'token' => $request->getPut('token')]);

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

            //Consulto lo necesario
            $user_current = json_decode($token_actual->user_current, true);
            $entidad = Entidades::findFirst($request->getPut('entidad'));

            $html_propuestas = "";

            //Genero reporte propuestas por estado
            $sql_convocatorias = "
                        SELECT vc.convocatoria,vc.categoria,cc.fecha_fin FROM Viewconvocatorias AS vc
                        INNER JOIN Convocatoriascronogramas AS cc ON cc.convocatoria=vc.id_diferente
                        WHERE vc.anio='" . $request->getPut('anio') . "' AND cc.tipo_evento=12 AND vc.entidad='" . $request->getPut('entidad') . "'
                        ORDER BY cc.fecha_inicio,vc.convocatoria";

            $convocatorias = $app->modelsManager->executeQuery($sql_convocatorias);

            foreach ($convocatorias as $convocatoria) {
                $html_propuestas = $html_propuestas . "<tr>";
                $html_propuestas = $html_propuestas . '<td colspan="2">' . $convocatoria->convocatoria . '</td>';
                $html_propuestas = $html_propuestas . "<td>" . $convocatoria->categoria . "</td>";
                $html_propuestas = $html_propuestas . "<td>" . $convocatoria->fecha_fin . "</td>";
                $html_propuestas = $html_propuestas . "</tr>";
            }





            $html = '<table border="1" cellpadding="2" cellspacing="2" nobr="true">
                    <tr>
                        <td colspan="4" align="center"> Convocatorias próximas a cerrar </td>
                    </tr>
                    <tr>
                        <td colspan="4" align="center"> Fecha de corte ' . date("Y-m-d H:i:s") . '</td>
                    </tr>
                    <tr>
                        <td colspan="2">Año: ' . $request->getPut('anio') . '</td>
                        <td colspan="2">Entidad: ' . $entidad->descripcion . '</td>
                    </tr>                                    
                    <tr style="background-color:#BDBDBD;color:#OOOOOO;">
                        <td align="center" colspan="2">Convocatoria</td>
                        <td align="center">Categoría</td>
                        <td align="center">Fecha de cierre</td>                                              
                    </tr> 
                    ' . $html_propuestas . '
                </table>';

            $logger->info('"token":"{token}","user":"{user}","message":"Se genero el reporte de inscripcion de la propuesta (' . $request->getPut('id') . ')', ['user' => $user_current["username"], 'token' => $request->getPut('token')]);
            $logger->close();
            echo $html;
        } else {
            //Registro la accion en el log de convocatorias           
            $logger->error('"token":"{token}","user":"{user}","message":"Token caduco en el metodo reporte_listado_entidades_convocatorias_cerrar al generar el reporte listado de la propuesta (' . $request->getPut('id') . ')', ['user' => "", 'token' => $request->getPut('token')]);
            $logger->close();
            echo "error_token";
        }
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias           
        $logger->error('"token":"{token}","user":"{user}","message":"Error metodo reporte_listado_entidades_convocatorias_cerrar al generar el reporte listado de la propuesta (' . $request->getPut('id') . ')' . $ex->getMessage() . '"', ['user' => "", 'token' => $request->getPut('token')]);
        $logger->close();
        echo "error_metodo";
    }
});

$app->post('/reporte_listado_entidades_convocatorias_total_jurados', function () use ($app, $config, $logger) {

//Instancio los objetos que se van a manejar
    $request = new Request();
    $tokens = new Tokens();

    try {

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPut('token'));

        //Registro la accion en el log de convocatorias
        $logger->info('"token":"{token}","user":"{user}","message":"Ingresa al metodo reporte_listado_entidades_convocatorias_total_jurados para generar reporte de listado de inscripcion de la propuesta (' . $request->getPut('id') . ')"', ['user' => '', 'token' => $request->getPut('token')]);

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

            //Consulto lo necesario
            $user_current = json_decode($token_actual->user_current, true);
            $entidad = Entidades::findFirst($request->getPut('entidad'));

            $html_propuestas = "";

            //Genero reporte propuestas por estado
            $sql_convocatorias = "
                        SELECT
                                e.nombre AS entidad,
                                c.nombre AS cnombre,
                                cp.nombre AS cpnombre,
                                pro.modalidad_participa,	
                                CASE jp.active
                                      WHEN TRUE THEN 'Activa'
                                      WHEN FALSE THEN 'Inactiva'

                                END as estado_postulacion,
                                count(jp.id) AS cantidad
                        FROM Juradospostulados AS jp
                            INNER JOIN Propuestas AS pro ON jp.propuesta = pro.id
                            LEFT JOIN Convocatorias as c ON jp.convocatoria = c.id
                            LEFT JOIN Entidades as e ON c.entidad =  e.id
                            LEFT JOIN Convocatorias as cp ON c.convocatoria_padre_categoria = cp.id
                        WHERE c.anio = " . $request->getPut('anio') . " AND c.entidad='" . $request->getPut('entidad') . "' 
                        GROUP BY 1,2,3,4,5
                        ORDER  BY 1,2,3,4,5";

            $convocatorias = $app->modelsManager->executeQuery($sql_convocatorias);

            foreach ($convocatorias as $convocatoria) {
                $nombre_convocatoria = $convocatoria->cnombre;
                if ($convocatoria->cpnombre) {
                    $nombre_convocatoria = $convocatoria->cpnombre . " - " . $convocatoria->cnombre;
                }

                $html_propuestas = $html_propuestas . "<tr>";
                $html_propuestas = $html_propuestas . '<td>' . $nombre_convocatoria . '</td>';
                $html_propuestas = $html_propuestas . "<td>" . $convocatoria->modalidad_participa . "</td>";
                $html_propuestas = $html_propuestas . "<td>" . $convocatoria->estado_postulacion . "</td>";
                $html_propuestas = $html_propuestas . "<td>" . $convocatoria->cantidad . "</td>";
                $html_propuestas = $html_propuestas . "</tr>";
            }

            $html = '<table border="1" cellpadding="2" cellspacing="2" nobr="true">
                    <tr>
                        <td colspan="4" align="center"> Cantidad de jurados por convocatoria </td>
                    </tr>
                    <tr>
                        <td colspan="4" align="center"> Fecha de corte ' . date("Y-m-d H:i:s") . '</td>
                    </tr>
                    <tr>
                        <td colspan="2">Año: ' . $request->getPut('anio') . '</td>
                        <td colspan="2">Entidad: ' . $entidad->descripcion . '</td>
                    </tr>                                    
                    <tr style="background-color:#BDBDBD;color:#OOOOOO;">
                        <td align="center">Convocatoria</td>
                        <td align="center">Modalidad Participa</td>
                        <td align="center">Estado Postulación</td>                                              
                        <td align="center">Total</td>                                              
                    </tr> 
                    ' . $html_propuestas . '
                </table>';

            $logger->info('"token":"{token}","user":"{user}","message":"Se genero el reporte de inscripcion de la propuesta (' . $request->getPut('id') . ')', ['user' => $user_current["username"], 'token' => $request->getPut('token')]);
            $logger->close();
            echo $html;
        } else {
            //Registro la accion en el log de convocatorias           
            $logger->error('"token":"{token}","user":"{user}","message":"Token caduco en el metodo reporte_listado_propuesta_rechazados_habilitados al generar el reporte listado de la propuesta (' . $request->getPut('id') . ')', ['user' => "", 'token' => $request->getPut('token')]);
            $logger->close();
            echo "error_token";
        }
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias           
        $logger->error('"token":"{token}","user":"{user}","message":"Error metodo reporte_listado_propuesta_rechazados_habilitados al generar el reporte listado de la propuesta (' . $request->getPut('id') . ')' . $ex->getMessage() . '"', ['user' => "", 'token' => $request->getPut('token')]);
        $logger->close();
        echo "error_metodo";
    }
});

$app->post('/reporte_listado_entidades_convocatorias_total_jurados_xls', function () use ($app, $config, $logger) {

    //Instancio los objetos que se van a manejar
    $request = new Request();
    $tokens = new Tokens();

    try {
        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPut('token'));

        //Registro la accion en el log de convocatorias
        $logger->info('"token":"{token}","user":"{user}","message":"Ingresa al metodo reporte_listado_entidades_convocatorias_total_jurados_xls para generar reporte de listado de inscripcion de la propuesta (' . $request->getPut('id') . ')"', ['user' => '', 'token' => $request->getPut('token')]);

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {


            require_once("../library/phpspreadsheet/autoload.php");

            $entidad = Entidades::findFirst($request->getPut('entidad'));
            $anio = $request->getPut('anio');

            //Genero reporte propuestas por estado
            $sql_convocatorias = "
            SELECT
                    e.nombre AS entidad,
                    c.nombre AS cnombre,
                    cp.nombre AS cpnombre,
                    pro.modalidad_participa,	
                    CASE jp.active
                          WHEN TRUE THEN 'Activa'
                          WHEN FALSE THEN 'Inactiva'

                    END as estado_postulacion,
                    count(jp.id) AS cantidad
            FROM Juradospostulados AS jp
                INNER JOIN Propuestas AS pro ON jp.propuesta = pro.id
                LEFT JOIN Convocatorias as c ON jp.convocatoria = c.id
                LEFT JOIN Entidades as e ON c.entidad =  e.id
                LEFT JOIN Convocatorias as cp ON c.convocatoria_padre_categoria = cp.id
            WHERE c.anio = " . $anio . " AND c.entidad='" . $entidad->id . "' 
            GROUP BY 1,2,3,4,5
            ORDER  BY 1,2,3,4,5";

            $convocatorias = $app->modelsManager->executeQuery($sql_convocatorias);

            $documento = new Spreadsheet();
            $documento
                    ->getProperties()
                    ->setCreator("SICON")
                    ->setLastModifiedBy('SICON') // última vez modificado por
                    ->setTitle('Cantidad de jurados por convocatoria')
                    ->setSubject('SICON')
                    ->setDescription('Cantidad de jurados por convocatoria')
                    ->setKeywords('SICON')
                    ->setCategory('La categoría');

            $hoja = $documento->getActiveSheet();
            $hoja->setTitle("Jurados por convocatoria");

            //Cabezote de la tabla
            $hoja->setCellValueByColumnAndRow(1, 1, "Cantidad de jurados por convocatoria");

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
            $hoja->setCellValueByColumnAndRow(2, 5, "Modalidad Participa");
            $hoja->setCellValueByColumnAndRow(3, 5, "Estado Postulación");
            $hoja->setCellValueByColumnAndRow(3, 5, "Total");

            //Registros de la base de datos
            $fila = 6;
            foreach ($convocatorias as $convocatoria) {
                $nombre_convocatoria = $convocatoria->cnombre;
                if ($convocatoria->cpnombre) {
                    $nombre_convocatoria = $convocatoria->cpnombre . " - " . $convocatoria->cnombre;
                }

                $hoja->setCellValueByColumnAndRow(1, $fila, $nombre_convocatoria);
                $hoja->setCellValueByColumnAndRow(2, $fila, $convocatoria->modalidad_participa);
                $hoja->setCellValueByColumnAndRow(3, $fila, $convocatoria->estado_postulacion);
                $hoja->setCellValueByColumnAndRow(3, $fila, $convocatoria->cantidad);
                $fila++;
            }


            $nombreDelDocumento = "listado_entidades_convocatorias_total_jurados_" . $entidad->id . "_" . $anio . ".xlsx";

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
            $logger->error('"token":"{token}","user":"{user}","message":"Token caduco en el metodo reporte_listado_entidades_convocatorias_total_jurados_xls al generar el reporte listado de la propuesta (' . $request->getPut('id') . ')', ['user' => "", 'token' => $request->getPut('token')]);
            $logger->close();
            echo "error_token";
        }
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias           
        $logger->error('"token":"{token}","user":"{user}","message":"Error metodo reporte_listado_entidades_convocatorias_total_jurados_xls al generar el reporte listado de la propuesta (' . $request->getPut('id') . ')' . $ex->getMessage() . '"', ['user' => "", 'token' => $request->getPut('token')]);
        $logger->close();
        echo "error_metodo";
    }
});

$app->post('/reporte_listado_entidades_convocatorias_listado_jurados', function () use ($app, $config, $logger) {

//Instancio los objetos que se van a manejar
    $request = new Request();
    $tokens = new Tokens();

    try {

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPut('token'));

        //Registro la accion en el log de convocatorias
        $logger->info('"token":"{token}","user":"{user}","message":"Ingresa al metodo reporte_listado_entidades_convocatorias_listado_jurados para generar reporte de listado de inscripcion de la propuesta (' . $request->getPut('id') . ')"', ['user' => '', 'token' => $request->getPut('token')]);

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

            //Consulto lo necesario
            $user_current = json_decode($token_actual->user_current, true);
            $entidad = Entidades::findFirst($request->getPut('entidad'));

            $html_propuestas = "";

            //Genero reporte propuestas por estado
            $sql_convocatorias = "
                        SELECT
                                pro.codigo AS codigo,
                                pro.nombre AS jurado,
                                pro.modalidad_participa,
                                e.nombre as entidad,
                                c.nombre AS cnombre,
                                cp.nombre AS cpnombre,
                                CASE jp.active
                                      WHEN jp.active = true   THEN 'activa'
                                      WHEN jp.active = false  THEN 'inactiva'
                                END as estado
                        FROM Juradospostulados AS jp
                            INNER JOIN Propuestas AS pro ON jp.propuesta = pro.id
                            LEFT JOIN Convocatorias AS c ON jp.convocatoria = c.id
                            LEFT JOIN Entidades AS e ON c.entidad =  e.id
                            LEFT JOIN Convocatorias AS cp ON c.convocatoria_padre_categoria = cp.id
                        WHERE c.anio = " . $request->getPut('anio') . " AND c.entidad='" . $request->getPut('entidad') . "' 
                        ORDER by 1,2,4,5,6";

            $convocatorias = $app->modelsManager->executeQuery($sql_convocatorias);

            foreach ($convocatorias as $convocatoria) {
                $nombre_convocatoria = $convocatoria->cnombre;
                if ($convocatoria->cpnombre) {
                    $nombre_convocatoria = $convocatoria->cpnombre . " - " . $convocatoria->cnombre;
                }

                $html_propuestas = $html_propuestas . "<tr>";
                $html_propuestas = $html_propuestas . "<td>" . $convocatoria->codigo . "</td>";
                $html_propuestas = $html_propuestas . "<td>" . $convocatoria->jurado . "</td>";
                $html_propuestas = $html_propuestas . "<td>" . $convocatoria->modalidad_participa . "</td>";
                $html_propuestas = $html_propuestas . '<td>' . $nombre_convocatoria . '</td>';
                $html_propuestas = $html_propuestas . "<td>" . $convocatoria->estado . "</td>";
                $html_propuestas = $html_propuestas . "</tr>";
            }

            $html = '<table border="1" cellpadding="2" cellspacing="2" nobr="true">
                    <tr>
                        <td colspan="5" align="center"> Listado de jurados por convocatoria </td>
                    </tr>
                    <tr>
                        <td colspan="5" align="center"> Fecha de corte ' . date("Y-m-d H:i:s") . '</td>
                    </tr>
                    <tr>
                        <td colspan="2">Año: ' . $request->getPut('anio') . '</td>
                        <td colspan="3">Entidad: ' . $entidad->descripcion . '</td>
                    </tr>                                    
                    <tr style="background-color:#BDBDBD;color:#OOOOOO;">
                        <td align="center">Código hoja de vida</td>
                        <td align="center">Jurado</td>
                        <td align="center">Modalidad Participa</td>
                        <td align="center">Convocatoria</td>                        
                        <td align="center">Estado Postulación</td>                        
                    </tr> 
                    ' . $html_propuestas . '
                </table>';

            $logger->info('"token":"{token}","user":"{user}","message":"Se genero el reporte de inscripcion de la propuesta (' . $request->getPut('id') . ')', ['user' => $user_current["username"], 'token' => $request->getPut('token')]);
            $logger->close();
            echo $html;
        } else {
            //Registro la accion en el log de convocatorias           
            $logger->error('"token":"{token}","user":"{user}","message":"Token caduco en el metodo reporte_listado_propuesta_rechazados_habilitados al generar el reporte listado de la propuesta (' . $request->getPut('id') . ')', ['user' => "", 'token' => $request->getPut('token')]);
            $logger->close();
            echo "error_token";
        }
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias           
        $logger->error('"token":"{token}","user":"{user}","message":"Error metodo reporte_listado_propuesta_rechazados_habilitados al generar el reporte listado de la propuesta (' . $request->getPut('id') . ')' . $ex->getMessage() . '"', ['user' => "", 'token' => $request->getPut('token')]);
        $logger->close();
        echo "error_metodo";
    }
});

$app->post('/reporte_listado_entidades_convocatorias_listado_jurados_xls', function () use ($app, $config, $logger) {

    //Instancio los objetos que se van a manejar
    $request = new Request();
    $tokens = new Tokens();

    try {
        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPut('token'));

        //Registro la accion en el log de convocatorias
        $logger->info('"token":"{token}","user":"{user}","message":"Ingresa al metodo reporte_listado_entidades_convocatorias_listado_jurados_xls para generar reporte de listado de inscripcion de la propuesta (' . $request->getPut('id') . ')"', ['user' => '', 'token' => $request->getPut('token')]);

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {


            require_once("../library/phpspreadsheet/autoload.php");

            $entidad = Entidades::findFirst($request->getPut('entidad'));
            $anio = $request->getPut('anio');

            //Genero reporte propuestas por estado
            $sql_convocatorias = "
            SELECT
                    pro.codigo AS codigo,
                    pro.nombre AS jurado,
                    pro.modalidad_participa,
                    e.nombre as entidad,
                    c.nombre AS cnombre,
                    cp.nombre AS cpnombre,
                    CASE jp.active
                          WHEN jp.active = true   THEN 'activa'
                          WHEN jp.active = false  THEN 'inactiva'
                    END as estado
            FROM Juradospostulados AS jp
                INNER JOIN Propuestas AS pro ON jp.propuesta = pro.id
                LEFT JOIN Convocatorias AS c ON jp.convocatoria = c.id
                LEFT JOIN Entidades AS e ON c.entidad =  e.id
                LEFT JOIN Convocatorias AS cp ON c.convocatoria_padre_categoria = cp.id
            WHERE c.anio = " . $request->getPut('anio') . " AND c.entidad='" . $request->getPut('entidad') . "' 
            ORDER by 1,2,4,5,6";

            $convocatorias = $app->modelsManager->executeQuery($sql_convocatorias);

            $documento = new Spreadsheet();
            $documento
                    ->getProperties()
                    ->setCreator("SICON")
                    ->setLastModifiedBy('SICON') // última vez modificado por
                    ->setTitle('Listado de jurados por convocatoria')
                    ->setSubject('SICON')
                    ->setDescription('Listado de jurados por convocatoria')
                    ->setKeywords('SICON')
                    ->setCategory('La categoría');

            $hoja = $documento->getActiveSheet();
            $hoja->setTitle("Jurados por convocatoria");

            //Cabezote de la tabla
            $hoja->setCellValueByColumnAndRow(1, 1, "Listado de jurados por convocatoria");

            //Cabezote de la tabla
            $hoja->setCellValueByColumnAndRow(1, 2, "Fecha de corte");
            $hoja->setCellValueByColumnAndRow(2, 2, date("Y-m-d H:i:s"));

            //Cabezote de la tabla
            $hoja->setCellValueByColumnAndRow(1, 3, "Año");
            $hoja->setCellValueByColumnAndRow(2, 3, $anio);
            $hoja->setCellValueByColumnAndRow(3, 3, "Entidad");
            $hoja->setCellValueByColumnAndRow(4, 3, $entidad->descripcion);

            //Cabezote de la tabla
            $hoja->setCellValueByColumnAndRow(1, 5, "Codigo hoja de vida");
            $hoja->setCellValueByColumnAndRow(2, 5, "Jurado");
            $hoja->setCellValueByColumnAndRow(3, 5, "Modalidad Participa");
            $hoja->setCellValueByColumnAndRow(4, 5, "Convocatoria");
            $hoja->setCellValueByColumnAndRow(5, 5, "Estado Postulación");

            //Registros de la base de datos
            $fila = 6;
            foreach ($convocatorias as $convocatoria) {
                $nombre_convocatoria = $convocatoria->cnombre;
                if ($convocatoria->cpnombre) {
                    $nombre_convocatoria = $convocatoria->cpnombre . " - " . $convocatoria->cnombre;
                }

                $hoja->setCellValueByColumnAndRow(1, $fila, $convocatoria->codigo);
                $hoja->setCellValueByColumnAndRow(2, $fila, $convocatoria->jurado);
                $hoja->setCellValueByColumnAndRow(3, $fila, $convocatoria->modalidad_participa);
                $hoja->setCellValueByColumnAndRow(4, $fila, $nombre_convocatoria);
                $hoja->setCellValueByColumnAndRow(5, $fila, $convocatoria->estado);
                $fila++;
            }


            $nombreDelDocumento = "listado_entidades_convocatorias_total_jurados_" . $entidad->id . "_" . $anio . ".xlsx";

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
            $logger->error('"token":"{token}","user":"{user}","message":"Token caduco en el metodo reporte_listado_entidades_convocatorias_listado_jurados_xls al generar el reporte listado de la propuesta (' . $request->getPut('id') . ')', ['user' => "", 'token' => $request->getPut('token')]);
            $logger->close();
            echo "error_token";
        }
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias           
        $logger->error('"token":"{token}","user":"{user}","message":"Error metodo reporte_listado_entidades_convocatorias_listado_jurados_xls al generar el reporte listado de la propuesta (' . $request->getPut('id') . ')' . $ex->getMessage() . '"', ['user' => "", 'token' => $request->getPut('token')]);
        $logger->close();
        echo "error_metodo";
    }
});

// Editar registro
$app->put('/editar_contratista/{id:[0-9]+}', function ($id) use ($app, $config) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPut('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

            //Usuario actual
            $user_current = json_decode($token_actual->user_current, true);

            //verificar si tiene permisos de escritura
            $permiso_escritura = $tokens->permiso_lectura($user_current["id"], $request->getPut('modulo'));

            //Verifico que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {
                $put = $app->request->getPut();
                // Consultar el usuario que se esta editando
                $pais = Entidadescontratistas::findFirst(json_decode($id));
                $pais->actualizado_por = $user_current["id"];
                $pais->fecha_actualizacion = date("Y-m-d H:i:s");
                if ($pais->save($put) === false) {
                    echo "error";
                } else {
                    echo $id;
                }
            } else {
                echo "acceso_denegado";
            }
        } else {
            echo "error";
        }
    } catch (Exception $ex) {
        echo "error_metodo";
    }
}
);

//Busca el registro
$app->get('/buscar_contratista/{id:[0-9]+}', function ($id) use ($app) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->get('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {
            $pais = Entidadescontratistas::findFirst($id);
            if (isset($pais->id)) {
                echo json_encode($pais);
            } else {
                echo "error";
            }
        } else {
            echo "error";
        }
    } catch (Exception $ex) {
        //retorno el array en json null
        echo "error_metodo";
    }
}
);

$app->post('/cargar_contratistas_csv', function () use ($app, $config, $logger) {

    //Instancio los objetos que se van a manejar
    $request = new Request();
    $tokens = new Tokens();
    try {
        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPut('token'));

        //Registro la accion en el log de convocatorias
        $logger->info('"token":"{token}","user":"{user}","message":"Ingresa al metodo cargar_contratistas_csv para generar reporte de listado de inscripcion de la propuesta (' . $request->getPut('id') . ')"', ['user' => '', 'token' => $request->getPut('token')]);

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

            $user_current = json_decode($token_actual->user_current, true);

            $linea = 0;
            $cabecera = "";
            $numero_documentos_error = "Número de documentos no cargados:";
            //Abrimos nuestro archivo
            $archivo = fopen($request->getPut("srcData"), "r");
            //Lo recorremos            
            while (($datos = fgetcsv($archivo, 1000, "\t")) == true) {
                $num = count($datos);
                if ($num != 7) {
                    $error = 1;
                    break;
                } else {
                    //Recorremos las columnas de esa linea                    
                    if ($linea == 0) {
                        for ($columna = 0; $columna < $num; $columna++) {
                            $cabecera = $cabecera . $datos[$columna] . ";";
                        }
                    }

                    if ($linea == 0) {
                        if ($cabecera != "numero_documento;primer_nombre;segundo_nombre;primer_apellido;segundo_apellido;activo;observaciones;") {
                            $error = 2;
                            break;
                        }
                    } else {

                        //WILLIAM OJO BUSCAR Y SI ESTA EDITAR                                                
                        $comsulta_contratista = Entidadescontratistas::findFirst("numero_documento='" . $datos[0] . "' AND entidad = " . $request->getPut("entidad"));

                        if (isset($comsulta_contratista->id)) {
                            $contratista = $comsulta_contratista;
                            $contratista->actualizado_por = $user_current["id"];
                            $contratista->fecha_actualizacion = date("Y-m-d H:i:s");
                        } else {
                            $contratista = new Entidadescontratistas();
                            $contratista->entidad = $request->getPut("entidad");
                            $contratista->creado_por = $user_current["id"];
                            $contratista->fecha_creacion = date("Y-m-d H:i:s");
                        }
                        $contratista->numero_documento = $datos[0];
                        $contratista->primer_nombre = utf8_encode($datos[1]);
                        $contratista->segundo_nombre = utf8_encode($datos[2]);
                        $contratista->primer_apellido = utf8_encode($datos[3]);
                        $contratista->segundo_apellido = utf8_encode($datos[4]);
                        $contratista->active = $datos[5];
                        $contratista->observaciones = utf8_encode($datos[6]);
                        if ($contratista->save() === false) {
                            $numero_documentos_error = $numero_documentos_error . "," . $datos[0];
                        }
                    }
                    $linea++;
                }
            }
            //Cerramos el archivo
            fclose($archivo);

            if ($error == 1) {
                echo "error_columnas";
            } else {
                if ($error == 2) {
                    echo "error_cabecera";
                } else {
                    echo $numero_documentos_error;
                }
            }
        } else {
            //Registro la accion en el log de convocatorias           
            $logger->error('"token":"{token}","user":"{user}","message":"Token caduco en el metodo cargar_contratistas_csv al generar el reporte listado de la propuesta (' . $request->getPut('id') . ')', ['user' => "", 'token' => $request->getPut('token')]);
            $logger->close();
            echo "error_token";
        }
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias           
        $logger->error('"token":"{token}","user":"{user}","message":"Error metodo cargar_contratistas_csv al generar el reporte listado de la propuesta (' . $request->getPut('id') . ')' . $ex->getMessage() . '"', ['user' => "", 'token' => $request->getPut('token')]);
        $logger->close();
        echo "error_metodo";
    }
});

$app->post('/reporte_listado_entidades_convocatorias_listado_contratistas', function () use ($app, $config, $logger) {

//Instancio los objetos que se van a manejar
    $request = new Request();
    $tokens = new Tokens();

    try {

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPut('token'));

        //Registro la accion en el log de convocatorias
        $logger->info('"token":"{token}","user":"{user}","message":"Ingresa al metodo reporte_listado_entidades_convocatorias_listado_jurados para generar reporte de listado de inscripcion de la propuesta (' . $request->getPut('id') . ')"', ['user' => '', 'token' => $request->getPut('token')]);

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

            //Consulto lo necesario
            $user_current = json_decode($token_actual->user_current, true);
            $entidad = Entidades::findFirst($request->getPut('entidad'));

            $html_propuestas = "";

            //Genero reporte propuestas por estado
            $sql_convocatorias = "
                        SELECT 
                                vwp.convocatoria,
                                vwp.id_convocatoria,
                                vwp.codigo,
                                vwp.nombre_propuesta,
                                vwp.tipo_participante,
                                vwp.representante,
                                vwp.tipo_rol,
                                vwp.rol,
                                vwp.numero_documento,
                                vwp.primer_nombre,
                                vwp.segundo_nombre,
                                vwp.primer_apellido,
                                vwp.segundo_apellido,
                                vwp.id_tipo_documento,
                                vwp.tipo_documento,
                                vwp.fecha_nacimiento,
                                vwp.sexo,
                                vwp.direccion_residencia,
                                vwp.ciudad_residencia,
                                vwp.localidad_residencia,
                                vwp.upz_residencia,
                                vwp.barrio_residencia,
                                vwp.estrato,
                                vwp.correo_electronico,
                                vwp.numero_telefono,
                                vwp.numero_celular,
                                array_agg(CONCAT(e.nombre,' ',ec.numero_documento,' ',ec.primer_nombre,' ',ec.segundo_nombre,' ',ec.primer_apellido,' ',ec.segundo_apellido)) AS contratista 
                        FROM Viewparticipantes AS vwp
                                INNER JOIN Entidadescontratistas AS ec ON REPLACE(TRIM(ec.numero_documento),'.','')= REPLACE(TRIM(vwp.numero_documento),'.','')
                                INNER JOIN Entidades AS e ON e.id=ec.entidad
                        WHERE vwp.id_convocatoria=" . $request->getPut('convocatoria') . " AND vwp.id_tipo_documento<>7
                        GROUP BY 1,2,3,4,5,6,7,8,9,10,11,12,13,14,15,16,17,18,19,20,21,22,23,24,25,26
                        ORDER BY vwp.codigo
                        ";

            $convocatorias = $app->modelsManager->executeQuery($sql_convocatorias);

            foreach ($convocatorias as $convocatoria) {
                $html_propuestas = $html_propuestas . "<tr>";
                $html_propuestas = $html_propuestas . "<td>" . $convocatoria->codigo . "</td>";
                $html_propuestas = $html_propuestas . "<td>" . $convocatoria->nombre_propuesta . "</td>";
                $html_propuestas = $html_propuestas . "<td>" . $convocatoria->tipo_participante . "</td>";
                $html_propuestas = $html_propuestas . "<td>" . $convocatoria->tipo_rol . "</td>";
                $html_propuestas = $html_propuestas . "<td>" . $convocatoria->rol . "</td>";
                $value_representante = "No";
                if ($convocatoria->representante) {
                    $value_representante = "Sí";
                }
                $html_propuestas = $html_propuestas . "<td>" . $value_representante . "</td>";
                $html_propuestas = $html_propuestas . "<td>" . $convocatoria->tipo_documento . "</td>";
                $html_propuestas = $html_propuestas . "<td>" . $convocatoria->numero_documento . "</td>";
                $html_propuestas = $html_propuestas . "<td>" . $convocatoria->primer_nombre . " " . $convocatoria->segundo_nombre . " " . $convocatoria->primer_apellido . " " . $convocatoria->segundo_apellido . "</td>";
                $html_propuestas = $html_propuestas . "<td>" . $convocatoria->fecha_nacimiento . "</td>";
                $html_propuestas = $html_propuestas . "<td>" . $convocatoria->sexo . "</td>";
                $html_propuestas = $html_propuestas . "<td>" . $convocatoria->direccion_residencia . "</td>";
                $html_propuestas = $html_propuestas . "<td>" . $convocatoria->ciudad_residencia . "</td>";
                $html_propuestas = $html_propuestas . "<td>" . $convocatoria->localidad_residencia . "</td>";
                $html_propuestas = $html_propuestas . "<td>" . $convocatoria->upz_residencia . "</td>";
                $html_propuestas = $html_propuestas . "<td>" . $convocatoria->barrio_residencia . "</td>";
                $html_propuestas = $html_propuestas . "<td>" . $convocatoria->estrato . "</td>";
                $html_propuestas = $html_propuestas . "<td>" . $convocatoria->correo_electronico . "</td>";
                $html_propuestas = $html_propuestas . "<td>" . $convocatoria->numero_telefono . "</td>";
                $html_propuestas = $html_propuestas . "<td>" . $convocatoria->numero_celular . "</td>";
                $value_contratista = str_replace("{", "", $convocatoria->contratista);
                $value_contratista = str_replace("}", "", $value_contratista);
                $value_contratista = str_replace('"', "", $value_contratista);
                $value_contratista = str_replace(',', "<br/><br/>", $value_contratista);
                $html_propuestas = $html_propuestas . '<td colspan="4">' . $value_contratista . '</td>';
                $html_propuestas = $html_propuestas . "</tr>";
            }

            //Consulto la convocatoria
            $convocatoria = Convocatorias::findFirst($request->getPut('convocatoria'));
            //Si la convocatoria seleccionada es categoria, debo invertir los nombres la convocatoria con la categoria
            $nombre_convocatoria = $convocatoria->nombre;
            $nombre_categoria = "";
            if ($convocatoria->convocatoria_padre_categoria > 0) {
                $nombre_convocatoria = $convocatoria->getConvocatorias()->nombre;
                $nombre_categoria = $convocatoria->nombre;
            }

            $html = '<table border="1" cellpadding="2" cellspacing="2" nobr="true">
                    <tr>
                        <td colspan="24" align="center">  Integrantes, Representantes y Participantes Contratistas  </td>
                    </tr>                    
                    <tr>
                        <td colspan="24" align="center"> Fecha de corte ' . date("Y-m-d H:i:s") . '</td>
                    </tr>
                    <tr>
                        <td colspan="12">Año: ' . $request->getPut('anio') . '</td>
                        <td colspan="12">Entidad: ' . $entidad->descripcion . '</td>
                    </tr>                                    
                    <tr>
                        <td colspan="12">Convocatoria: ' . $nombre_convocatoria . '</td>
                        <td colspan="12">Categoría: ' . $nombre_categoria . '</td>
                    </tr>                                    
                    <tr style="background-color:#BDBDBD;color:#OOOOOO;">
                        <td align="center">Código Propuesta</td>                        
                        <td align="center">Propuesta Nombre</td>
                        <td align="center">Tipo Participante</td>                        
                        <td align="center">Tipo Rol</td>
                        <td align="center">Rol</td>
                        <td align="center">¿Representante?</td>
                        <td align="center">Tipo de documento</td>
                        <td align="center">Número de documento</td>
                        <td align="center">Nombres y Apellidos</td>                        
                        <td align="center">Fecha Nacimiento</td>
                        <td align="center">Sexo</td>
                        <td align="center">Dir. Residencia</td>
                        <td align="center">Ciudad de residencia</td>                        
                        <td align="center">Localidad Residencia</td>
                        <td align="center">Upz Residencia</td>
                        <td align="center">Barrio Residencia</td>
                        <td align="center">Estrato</td>
                        <td align="center">Correo electrónico</td>
                        <td align="center">Tel Fijo</td>
                        <td align="center">Tel Celular</td>
                        <td align="center" colspan="4">Contratista en:</td>                         
                    </tr> 
                    ' . $html_propuestas . '
                </table>';

            $logger->info('"token":"{token}","user":"{user}","message":"Se genero el reporte de inscripcion de la propuesta (' . $request->getPut('id') . ')', ['user' => $user_current["username"], 'token' => $request->getPut('token')]);
            $logger->close();
            echo $html;
        } else {
            //Registro la accion en el log de convocatorias           
            $logger->error('"token":"{token}","user":"{user}","message":"Token caduco en el metodo reporte_listado_propuesta_rechazados_habilitados al generar el reporte listado de la propuesta (' . $request->getPut('id') . ')', ['user' => "", 'token' => $request->getPut('token')]);
            $logger->close();
            echo "error_token";
        }
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias           
        $logger->error('"token":"{token}","user":"{user}","message":"Error metodo reporte_listado_propuesta_rechazados_habilitados al generar el reporte listado de la propuesta (' . $request->getPut('id') . ')' . $ex->getMessage() . '"', ['user' => "", 'token' => $request->getPut('token')]);
        $logger->close();
        echo "error_metodo";
    }
});

$app->post('/reporte_listado_entidades_convocatorias_listado_contratistas_xls', function () use ($app, $config, $logger) {

    //Instancio los objetos que se van a manejar
    $request = new Request();
    $tokens = new Tokens();

    try {
        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPut('token'));

        //Registro la accion en el log de convocatorias
        $logger->info('"token":"{token}","user":"{user}","message":"Ingresa al metodo reporte_listado_entidades_convocatorias_listado_jurados_xls para generar reporte de listado de inscripcion de la propuesta (' . $request->getPut('id') . ')"', ['user' => '', 'token' => $request->getPut('token')]);

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {


            require_once("../library/phpspreadsheet/autoload.php");

            $entidad = Entidades::findFirst($request->getPut('entidad'));
            $anio = $request->getPut('anio');

            //Genero reporte propuestas por estado
            $sql_convocatorias = "
            SELECT 
                    vwp.convocatoria,
                    vwp.id_convocatoria,
                    vwp.codigo,
                    vwp.nombre_propuesta,
                    vwp.tipo_participante,
                    vwp.representante,
                    vwp.tipo_rol,
                    vwp.rol,
                    vwp.numero_documento,
                    vwp.primer_nombre,
                    vwp.segundo_nombre,
                    vwp.primer_apellido,
                    vwp.segundo_apellido,
                    vwp.id_tipo_documento,
                    vwp.tipo_documento,
                    vwp.fecha_nacimiento,
                    vwp.sexo,
                    vwp.direccion_residencia,
                    vwp.ciudad_residencia,
                    vwp.localidad_residencia,
                    vwp.upz_residencia,
                    vwp.barrio_residencia,
                    vwp.estrato,
                    vwp.correo_electronico,
                    vwp.numero_telefono,
                    vwp.numero_celular,
                    array_agg(CONCAT(e.nombre,' ',ec.numero_documento,' ',ec.primer_nombre,' ',ec.segundo_nombre,' ',ec.primer_apellido,' ',ec.segundo_apellido)) AS contratista 
            FROM Viewparticipantes AS vwp
                    INNER JOIN Entidadescontratistas AS ec ON REPLACE(TRIM(ec.numero_documento),'.','')= REPLACE(TRIM(vwp.numero_documento),'.','')
                    INNER JOIN Entidades AS e ON e.id=ec.entidad
            WHERE vwp.id_convocatoria=" . $request->getPut('convocatoria') . " AND vwp.id_tipo_documento<>7
            GROUP BY 1,2,3,4,5,6,7,8,9,10,11,12,13,14,15,16,17,18,19,20,21,22,23,24,25,26
            ORDER BY vwp.codigo
            ";

            $convocatorias = $app->modelsManager->executeQuery($sql_convocatorias);

            $documento = new Spreadsheet();
            $documento
                    ->getProperties()
                    ->setCreator("SICON")
                    ->setLastModifiedBy('SICON') // última vez modificado por
                    ->setTitle('Listado de contratistas')
                    ->setSubject('SICON')
                    ->setDescription('Listado de contratistas')
                    ->setKeywords('SICON')
                    ->setCategory('La categoría');

            $hoja = $documento->getActiveSheet();
            $hoja->setTitle("Listado de contratistas");

            //Cabezote de la tabla
            $hoja->setCellValueByColumnAndRow(1, 1, "Integrantes, Representantes y Participantes Contratistas");

            //Cabezote de la tabla
            $hoja->setCellValueByColumnAndRow(1, 2, "Fecha de corte");
            $hoja->setCellValueByColumnAndRow(2, 2, date("Y-m-d H:i:s"));

            //Cabezote de la tabla
            $hoja->setCellValueByColumnAndRow(1, 3, "Año");
            $hoja->setCellValueByColumnAndRow(2, 3, $anio);
            $hoja->setCellValueByColumnAndRow(3, 3, "Entidad");
            $hoja->setCellValueByColumnAndRow(4, 3, $entidad->descripcion);

            //Consulto la convocatoria
            $convocatoria = Convocatorias::findFirst($request->getPut('convocatoria'));
            //Si la convocatoria seleccionada es categoria, debo invertir los nombres la convocatoria con la categoria
            $nombre_convocatoria = $convocatoria->nombre;
            $nombre_categoria = "";
            if ($convocatoria->convocatoria_padre_categoria > 0) {
                $nombre_convocatoria = $convocatoria->getConvocatorias()->nombre;
                $nombre_categoria = $convocatoria->nombre;
            }

            //Cabezote de la tabla
            $hoja->setCellValueByColumnAndRow(1, 4, "Convocatoria");
            $hoja->setCellValueByColumnAndRow(2, 4, $nombre_convocatoria);
            $hoja->setCellValueByColumnAndRow(3, 4, "Categoría");
            $hoja->setCellValueByColumnAndRow(4, 4, $nombre_categoria);

            //Cabezote de la tabla
            $hoja->setCellValueByColumnAndRow(1, 6, "Código Propuesta");
            $hoja->setCellValueByColumnAndRow(2, 6, "Propuesta Nombre");
            $hoja->setCellValueByColumnAndRow(3, 6, "Tipo Participante");
            $hoja->setCellValueByColumnAndRow(4, 6, "Tipo Rol");
            $hoja->setCellValueByColumnAndRow(5, 6, "Rol");
            $hoja->setCellValueByColumnAndRow(6, 6, "¿Representante?");
            $hoja->setCellValueByColumnAndRow(7, 6, "Tipo de documento");
            $hoja->setCellValueByColumnAndRow(8, 6, "Número de documento");
            $hoja->setCellValueByColumnAndRow(9, 6, "Nombres y Apellidos");
            $hoja->setCellValueByColumnAndRow(10, 6, "Fecha Nacimiento");
            $hoja->setCellValueByColumnAndRow(11, 6, "Sexo");
            $hoja->setCellValueByColumnAndRow(12, 6, "Dir. Residencia");
            $hoja->setCellValueByColumnAndRow(13, 6, "Ciudad de residencia");
            $hoja->setCellValueByColumnAndRow(14, 6, "Localidad Residencia");
            $hoja->setCellValueByColumnAndRow(15, 6, "Upz Residencia");
            $hoja->setCellValueByColumnAndRow(16, 6, "Barrio Residencia");
            $hoja->setCellValueByColumnAndRow(17, 6, "Estrato");
            $hoja->setCellValueByColumnAndRow(18, 6, "Correo electrónico");
            $hoja->setCellValueByColumnAndRow(19, 6, "Tel Fijo");
            $hoja->setCellValueByColumnAndRow(20, 6, "Tel Celular");
            $hoja->setCellValueByColumnAndRow(21, 6, "Contratista en:");

            //Registros de la base de datos
            $fila = 7;
            foreach ($convocatorias as $convocatoria) {

                $hoja->setCellValueByColumnAndRow(1, $fila, $convocatoria->codigo);
                $hoja->setCellValueByColumnAndRow(2, $fila, $convocatoria->nombre_propuesta);
                $hoja->setCellValueByColumnAndRow(3, $fila, $convocatoria->tipo_participante);
                $hoja->setCellValueByColumnAndRow(4, $fila, $convocatoria->tipo_rol);
                $hoja->setCellValueByColumnAndRow(5, $fila, $convocatoria->rol);
                $value_representante = "No";
                if ($convocatoria->representante) {
                    $value_representante = "Sí";
                }
                $hoja->setCellValueByColumnAndRow(6, $fila, $value_representante);
                $hoja->setCellValueByColumnAndRow(7, $fila, $convocatoria->tipo_documento);
                $hoja->setCellValueByColumnAndRow(8, $fila, $convocatoria->numero_documento);
                $hoja->setCellValueByColumnAndRow(9, $fila, $convocatoria->primer_nombre . " " . $convocatoria->segundo_nombre . " " . $convocatoria->primer_apellido . " " . $convocatoria->segundo_apellido);
                $hoja->setCellValueByColumnAndRow(10, $fila, $convocatoria->fecha_nacimiento);
                $hoja->setCellValueByColumnAndRow(11, $fila, $convocatoria->sexo);
                $hoja->setCellValueByColumnAndRow(12, $fila, $convocatoria->direccion_residencia);
                $hoja->setCellValueByColumnAndRow(13, $fila, $convocatoria->ciudad_residencia);
                $hoja->setCellValueByColumnAndRow(14, $fila, $convocatoria->localidad_residencia);
                $hoja->setCellValueByColumnAndRow(15, $fila, $convocatoria->upz_residencia);
                $hoja->setCellValueByColumnAndRow(16, $fila, $convocatoria->barrio_residencia);
                $hoja->setCellValueByColumnAndRow(17, $fila, $convocatoria->estrato);
                $hoja->setCellValueByColumnAndRow(18, $fila, $convocatoria->correo_electronico);
                $hoja->setCellValueByColumnAndRow(19, $fila, $convocatoria->numero_telefono);
                $hoja->setCellValueByColumnAndRow(20, $fila, $convocatoria->numero_celular);
                $value_contratista = str_replace("{", "", $convocatoria->contratista);
                $value_contratista = str_replace("}", "", $value_contratista);
                $value_contratista = str_replace('"', "", $value_contratista);
                $value_contratista = str_replace(',', "\n\n", $value_contratista);
                $hoja->setCellValueByColumnAndRow(21, $fila, $value_contratista);
                $fila++;
            }

            $nombreDelDocumento = "listado_entidades_convocatorias_listado_contratistas_" . $entidad->id . "_" . $anio . ".xlsx";

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
            $logger->error('"token":"{token}","user":"{user}","message":"Token caduco en el metodo reporte_listado_entidades_convocatorias_listado_jurados_xls al generar el reporte listado de la propuesta (' . $request->getPut('id') . ')', ['user' => "", 'token' => $request->getPut('token')]);
            $logger->close();
            echo "error_token";
        }
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias           
        $logger->error('"token":"{token}","user":"{user}","message":"Error metodo reporte_listado_entidades_convocatorias_listado_jurados_xls al generar el reporte listado de la propuesta (' . $request->getPut('id') . ')' . $ex->getMessage() . '"', ['user' => "", 'token' => $request->getPut('token')]);
        $logger->close();
        echo "error_metodo";
    }
});

$app->post('/reporte_listado_entidades_convocatorias_listado_participantes', function () use ($app, $config, $logger) {

//Instancio los objetos que se van a manejar
    $request = new Request();
    $tokens = new Tokens();

    try {

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPut('token'));

        //Registro la accion en el log de convocatorias
        $logger->info('"token":"{token}","user":"{user}","message":"Ingresa al metodo reporte_listado_entidades_convocatorias_listado_jurados para generar reporte de listado de inscripcion de la propuesta (' . $request->getPut('id') . ')"', ['user' => '', 'token' => $request->getPut('token')]);

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

            //Le permito mas memoria a la accion
            ini_set('memory_limit', '-1');

            //Consulto lo necesario
            $user_current = json_decode($token_actual->user_current, true);
            $entidad = Entidades::findFirst($request->getPut('entidad'));
            $convocatoria = $request->getPut('convocatoria');

            $html_propuestas = "";

            if ($convocatoria > 0) {
                //Genero reporte propuestas por estado
                $sql_convocatorias = "
                            SELECT 
                                    vwp.convocatoria,
                                    vwp.id_convocatoria,
                                    vwp.codigo,
                                    vwp.nombre_propuesta,
                                    vwp.tipo_participante,
                                    vwp.representante,
                                    vwp.tipo_rol,
                                    vwp.rol,
                                    vwp.numero_documento,
                                    vwp.primer_nombre,
                                    vwp.segundo_nombre,
                                    vwp.primer_apellido,
                                    vwp.segundo_apellido,
                                    vwp.id_tipo_documento,
                                    vwp.tipo_documento,
                                    vwp.fecha_nacimiento,
                                    vwp.sexo,
                                    vwp.direccion_residencia,
                                    vwp.ciudad_residencia,
                                    vwp.localidad_residencia,
                                    vwp.upz_residencia,
                                    vwp.barrio_residencia,
                                    vwp.estrato,
                                    vwp.correo_electronico,
                                    vwp.numero_telefono,
                                    vwp.numero_celular
                            FROM Viewparticipantes AS vwp                                
                            WHERE vwp.id_convocatoria=" . $request->getPut('convocatoria') . "
                            ORDER BY vwp.codigo
                            ";

                $convocatorias = $app->modelsManager->executeQuery($sql_convocatorias);

                foreach ($convocatorias as $convocatoria) {
                    $html_propuestas = $html_propuestas . "<tr>";
                    $html_propuestas = $html_propuestas . "<td>" . $convocatoria->codigo . "</td>";
                    $html_propuestas = $html_propuestas . "<td>" . $convocatoria->nombre_propuesta . "</td>";
                    $html_propuestas = $html_propuestas . "<td>" . $convocatoria->tipo_participante . "</td>";
                    $html_propuestas = $html_propuestas . "<td>" . $convocatoria->tipo_rol . "</td>";
                    $html_propuestas = $html_propuestas . "<td>" . $convocatoria->rol . "</td>";
                    $value_representante = "No";
                    if ($convocatoria->representante) {
                        $value_representante = "Sí";
                    }
                    $html_propuestas = $html_propuestas . "<td>" . $value_representante . "</td>";
                    $html_propuestas = $html_propuestas . "<td>" . $convocatoria->tipo_documento . "</td>";
                    $html_propuestas = $html_propuestas . "<td>" . $convocatoria->numero_documento . "</td>";
                    $html_propuestas = $html_propuestas . "<td>" . $convocatoria->primer_nombre . " " . $convocatoria->segundo_nombre . " " . $convocatoria->primer_apellido . " " . $convocatoria->segundo_apellido . "</td>";
                    $html_propuestas = $html_propuestas . "<td>" . $convocatoria->fecha_nacimiento . "</td>";
                    $html_propuestas = $html_propuestas . "<td>" . $convocatoria->sexo . "</td>";
                    $html_propuestas = $html_propuestas . "<td>" . $convocatoria->direccion_residencia . "</td>";
                    $html_propuestas = $html_propuestas . "<td>" . $convocatoria->ciudad_residencia . "</td>";
                    $html_propuestas = $html_propuestas . "<td>" . $convocatoria->localidad_residencia . "</td>";
                    $html_propuestas = $html_propuestas . "<td>" . $convocatoria->upz_residencia . "</td>";
                    $html_propuestas = $html_propuestas . "<td>" . $convocatoria->barrio_residencia . "</td>";
                    $html_propuestas = $html_propuestas . "<td>" . $convocatoria->estrato . "</td>";
                    $html_propuestas = $html_propuestas . "<td>" . $convocatoria->correo_electronico . "</td>";
                    $html_propuestas = $html_propuestas . "<td>" . $convocatoria->numero_telefono . "</td>";
                    $html_propuestas = $html_propuestas . "<td>" . $convocatoria->numero_celular . "</td>";
                    $html_propuestas = $html_propuestas . "</tr>";
                }

                //Consulto la convocatoria
                $convocatoria = Convocatorias::findFirst($request->getPut('convocatoria'));
                //Si la convocatoria seleccionada es categoria, debo invertir los nombres la convocatoria con la categoria
                $nombre_convocatoria = $convocatoria->nombre;
                $nombre_categoria = "";
                if ($convocatoria->convocatoria_padre_categoria > 0) {
                    $nombre_convocatoria = $convocatoria->getConvocatorias()->nombre;
                    $nombre_categoria = $convocatoria->nombre;
                }

                $html = '<table border="1" cellpadding="2" cellspacing="2" nobr="true">
                        <tr>
                            <td colspan="20" align="center"> Integrantes, Representantes y Participantes por convocatoria  </td>
                        </tr>                    
                        <tr>
                            <td colspan="20" align="center"> Fecha de corte ' . date("Y-m-d H:i:s") . '</td>
                        </tr>
                        <tr>
                            <td colspan="10">Año: ' . $request->getPut('anio') . '</td>
                            <td colspan="10">Entidad: ' . $entidad->descripcion . '</td>
                        </tr>                                    
                        <tr>
                            <td colspan="10">Convocatoria: ' . $nombre_convocatoria . '</td>
                            <td colspan="10">Categoría: ' . $nombre_categoria . '</td>
                        </tr>                                    
                        <tr style="background-color:#BDBDBD;color:#OOOOOO;">
                            <td align="center">Código Propuesta</td>                        
                            <td align="center">Propuesta Nombre</td>
                            <td align="center">Tipo Participante</td>                        
                            <td align="center">Tipo Rol</td>
                            <td align="center">Rol</td>
                            <td align="center">¿Representante?</td>
                            <td align="center">Tipo de documento</td>
                            <td align="center">Número de documento</td>
                            <td align="center">Nombres y Apellidos</td>                        
                            <td align="center">Fecha Nacimiento</td>
                            <td align="center">Sexo</td>
                            <td align="center">Dir. Residencia</td>
                            <td align="center">Ciudad de residencia</td>                        
                            <td align="center">Localidad Residencia</td>
                            <td align="center">Upz Residencia</td>
                            <td align="center">Barrio Residencia</td>
                            <td align="center">Estrato</td>
                            <td align="center">Correo electrónico</td>
                            <td align="center">Tel Fijo</td>
                            <td align="center">Tel Celular</td>                                                
                        </tr> 
                        ' . $html_propuestas . '
                    </table>';
            } else {

                //Genero reporte propuestas por estado
                $sql_convocatorias = "
                            SELECT 
                                    vwp.convocatoria,
                                    vwp.categoria,
                                    vwp.id_convocatoria,
                                    vwp.codigo,
                                    vwp.nombre_propuesta,
                                    vwp.tipo_participante,
                                    vwp.representante,
                                    vwp.tipo_rol,
                                    vwp.rol,
                                    vwp.numero_documento,
                                    vwp.primer_nombre,
                                    vwp.segundo_nombre,
                                    vwp.primer_apellido,
                                    vwp.segundo_apellido,
                                    vwp.id_tipo_documento,
                                    vwp.tipo_documento,
                                    vwp.fecha_nacimiento,
                                    vwp.sexo,
                                    vwp.direccion_residencia,
                                    vwp.ciudad_residencia,
                                    vwp.localidad_residencia,
                                    vwp.upz_residencia,
                                    vwp.barrio_residencia,
                                    vwp.estrato,
                                    vwp.correo_electronico,
                                    vwp.numero_telefono,
                                    vwp.numero_celular
                            FROM Viewparticipantes AS vwp                                
                            WHERE vwp.id_entidad=" . $entidad->id . "
                            ORDER BY vwp.codigo
                            ";

                $convocatorias = $app->modelsManager->executeQuery($sql_convocatorias);

                foreach ($convocatorias as $convocatoria) {
                    $html_propuestas = $html_propuestas . "<tr>";
                    $html_propuestas = $html_propuestas . "<td>" . $convocatoria->convocatoria . "</td>";
                    $html_propuestas = $html_propuestas . "<td>" . $convocatoria->categoria . "</td>";
                    $html_propuestas = $html_propuestas . "<td>" . $convocatoria->codigo . "</td>";
                    $html_propuestas = $html_propuestas . "<td>" . $convocatoria->nombre_propuesta . "</td>";
                    $html_propuestas = $html_propuestas . "<td>" . $convocatoria->tipo_participante . "</td>";
                    $html_propuestas = $html_propuestas . "<td>" . $convocatoria->tipo_rol . "</td>";
                    $html_propuestas = $html_propuestas . "<td>" . $convocatoria->rol . "</td>";
                    $value_representante = "No";
                    if ($convocatoria->representante) {
                        $value_representante = "Sí";
                    }
                    $html_propuestas = $html_propuestas . "<td>" . $value_representante . "</td>";
                    $html_propuestas = $html_propuestas . "<td>" . $convocatoria->tipo_documento . "</td>";
                    $html_propuestas = $html_propuestas . "<td>" . $convocatoria->numero_documento . "</td>";
                    $html_propuestas = $html_propuestas . "<td>" . $convocatoria->primer_nombre . " " . $convocatoria->segundo_nombre . " " . $convocatoria->primer_apellido . " " . $convocatoria->segundo_apellido . "</td>";
                    $html_propuestas = $html_propuestas . "<td>" . $convocatoria->fecha_nacimiento . "</td>";
                    $html_propuestas = $html_propuestas . "<td>" . $convocatoria->sexo . "</td>";
                    $html_propuestas = $html_propuestas . "<td>" . $convocatoria->direccion_residencia . "</td>";
                    $html_propuestas = $html_propuestas . "<td>" . $convocatoria->ciudad_residencia . "</td>";
                    $html_propuestas = $html_propuestas . "<td>" . $convocatoria->localidad_residencia . "</td>";
                    $html_propuestas = $html_propuestas . "<td>" . $convocatoria->upz_residencia . "</td>";
                    $html_propuestas = $html_propuestas . "<td>" . $convocatoria->barrio_residencia . "</td>";
                    $html_propuestas = $html_propuestas . "<td>" . $convocatoria->estrato . "</td>";
                    $html_propuestas = $html_propuestas . "<td>" . $convocatoria->correo_electronico . "</td>";
                    $html_propuestas = $html_propuestas . "<td>" . $convocatoria->numero_telefono . "</td>";
                    $html_propuestas = $html_propuestas . "<td>" . $convocatoria->numero_celular . "</td>";
                    $html_propuestas = $html_propuestas . "</tr>";
                }

                $html = '<table border="1" cellpadding="2" cellspacing="2" nobr="true">
                        <tr>
                            <td colspan="20" align="center"> Integrantes, Representantes y Participantes por convocatoria  </td>
                        </tr>                    
                        <tr>
                            <td colspan="20" align="center"> Fecha de corte ' . date("Y-m-d H:i:s") . '</td>
                        </tr>
                        <tr>
                            <td colspan="10">Año: ' . $request->getPut('anio') . '</td>
                            <td colspan="10">Entidad: ' . $entidad->descripcion . '</td>
                        </tr>                                    
                        <tr style="background-color:#BDBDBD;color:#OOOOOO;">
                            <td align="center">Convocatoria</td>                        
                            <td align="center">Categoría</td>
                            <td align="center">Código Propuesta</td>                        
                            <td align="center">Propuesta Nombre</td>
                            <td align="center">Tipo Participante</td>                        
                            <td align="center">Tipo Rol</td>
                            <td align="center">Rol</td>
                            <td align="center">¿Representante?</td>
                            <td align="center">Tipo de documento</td>
                            <td align="center">Número de documento</td>
                            <td align="center">Nombres y Apellidos</td>                        
                            <td align="center">Fecha Nacimiento</td>
                            <td align="center">Sexo</td>
                            <td align="center">Dir. Residencia</td>
                            <td align="center">Ciudad de residencia</td>                        
                            <td align="center">Localidad Residencia</td>
                            <td align="center">Upz Residencia</td>
                            <td align="center">Barrio Residencia</td>
                            <td align="center">Estrato</td>
                            <td align="center">Correo electrónico</td>
                            <td align="center">Tel Fijo</td>
                            <td align="center">Tel Celular</td>                                                
                        </tr> 
                        ' . $html_propuestas . '
                    </table>';
            }

            $logger->info('"token":"{token}","user":"{user}","message":"Se genero el reporte de inscripcion de la propuesta (' . $request->getPut('id') . ')', ['user' => $user_current["username"], 'token' => $request->getPut('token')]);
            $logger->close();
            echo $html;
        } else {
            //Registro la accion en el log de convocatorias           
            $logger->error('"token":"{token}","user":"{user}","message":"Token caduco en el metodo reporte_listado_propuesta_rechazados_habilitados al generar el reporte listado de la propuesta (' . $request->getPut('id') . ')', ['user' => "", 'token' => $request->getPut('token')]);
            $logger->close();
            echo "error_token";
        }
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias           
        $logger->error('"token":"{token}","user":"{user}","message":"Error metodo reporte_listado_propuesta_rechazados_habilitados al generar el reporte listado de la propuesta (' . $request->getPut('id') . ')' . $ex->getMessage() . '"', ['user' => "", 'token' => $request->getPut('token')]);
        $logger->close();
        echo "error_metodo";
    }
});

$app->post('/reporte_listado_entidades_convocatorias_listado_participantes_xls', function () use ($app, $config, $logger) {

    //Instancio los objetos que se van a manejar
    $request = new Request();
    $tokens = new Tokens();

    try {
        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPut('token'));

        //Registro la accion en el log de convocatorias
        $logger->info('"token":"{token}","user":"{user}","message":"Ingresa al metodo reporte_listado_entidades_convocatorias_listado_jurados_xls para generar reporte de listado de inscripcion de la propuesta (' . $request->getPut('id') . ')"', ['user' => '', 'token' => $request->getPut('token')]);

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {


            require_once("../library/phpspreadsheet/autoload.php");

            //Le permito mas memoria a la accion
            ini_set('memory_limit', '-1');

            $entidad = Entidades::findFirst($request->getPut('entidad'));
            $anio = $request->getPut('anio');
            $convocatoria = $request->getPut('convocatoria');

            if ($convocatoria > 0) {
                //Genero reporte propuestas por estado
                $sql_convocatorias = "
                SELECT 
                        vwp.convocatoria,
                        vwp.id_convocatoria,
                        vwp.codigo,
                        vwp.nombre_propuesta,
                        vwp.tipo_participante,
                        vwp.representante,
                        vwp.tipo_rol,
                        vwp.rol,
                        vwp.numero_documento,
                        vwp.primer_nombre,
                        vwp.segundo_nombre,
                        vwp.primer_apellido,
                        vwp.segundo_apellido,
                        vwp.id_tipo_documento,
                        vwp.tipo_documento,
                        vwp.fecha_nacimiento,
                        vwp.sexo,
                        vwp.direccion_residencia,
                        vwp.ciudad_residencia,
                        vwp.localidad_residencia,
                        vwp.upz_residencia,
                        vwp.barrio_residencia,
                        vwp.estrato,
                        vwp.correo_electronico,
                        vwp.numero_telefono,
                        vwp.numero_celular
                FROM Viewparticipantes AS vwp                    
                WHERE vwp.id_convocatoria=" . $convocatoria . "
                ORDER BY vwp.codigo
                ";
            } else {
                //Genero reporte propuestas por estado
                $sql_convocatorias = "
                SELECT 
                        vwp.convocatoria,
                        vwp.categoria,
                        vwp.id_convocatoria,
                        vwp.codigo,
                        vwp.nombre_propuesta,
                        vwp.tipo_participante,
                        vwp.representante,
                        vwp.tipo_rol,
                        vwp.rol,
                        vwp.numero_documento,
                        vwp.primer_nombre,
                        vwp.segundo_nombre,
                        vwp.primer_apellido,
                        vwp.segundo_apellido,
                        vwp.id_tipo_documento,
                        vwp.tipo_documento,
                        vwp.fecha_nacimiento,
                        vwp.sexo,
                        vwp.direccion_residencia,
                        vwp.ciudad_residencia,
                        vwp.localidad_residencia,
                        vwp.upz_residencia,
                        vwp.barrio_residencia,
                        vwp.estrato,
                        vwp.correo_electronico,
                        vwp.numero_telefono,
                        vwp.numero_celular
                FROM Viewparticipantes AS vwp                    
                WHERE vwp.id_entidad=" . $entidad->id . "
                ORDER BY vwp.codigo
                ";
            }

            $convocatorias = $app->modelsManager->executeQuery($sql_convocatorias);

            $documento = new Spreadsheet();
            $documento
                    ->getProperties()
                    ->setCreator("SICON")
                    ->setLastModifiedBy('SICON') // última vez modificado por
                    ->setTitle('Listado de contratistas')
                    ->setSubject('SICON')
                    ->setDescription('Listado de contratistas')
                    ->setKeywords('SICON')
                    ->setCategory('La categoría');

            $hoja = $documento->getActiveSheet();
            $hoja->setTitle("Listado de contratistas");

            //Cabezote de la tabla
            $hoja->setCellValueByColumnAndRow(1, 1, "Integrantes, Representantes y Participantes Contratistas");

            //Cabezote de la tabla
            $hoja->setCellValueByColumnAndRow(1, 2, "Fecha de corte");
            $hoja->setCellValueByColumnAndRow(2, 2, date("Y-m-d H:i:s"));

            //Cabezote de la tabla
            $hoja->setCellValueByColumnAndRow(1, 3, "Año");
            $hoja->setCellValueByColumnAndRow(2, 3, $anio);
            $hoja->setCellValueByColumnAndRow(3, 3, "Entidad");
            $hoja->setCellValueByColumnAndRow(4, 3, $entidad->descripcion);


            if ($convocatoria > 0) {
                //Consulto la convocatoria
                $convocatoria = Convocatorias::findFirst($request->getPut('convocatoria'));
                //Si la convocatoria seleccionada es categoria, debo invertir los nombres la convocatoria con la categoria
                $nombre_convocatoria = $convocatoria->nombre;
                $nombre_categoria = "";
                if ($convocatoria->convocatoria_padre_categoria > 0) {
                    $nombre_convocatoria = $convocatoria->getConvocatorias()->nombre;
                    $nombre_categoria = $convocatoria->nombre;
                }

                //Cabezote de la tabla
                $hoja->setCellValueByColumnAndRow(1, 4, "Convocatoria");
                $hoja->setCellValueByColumnAndRow(2, 4, $nombre_convocatoria);
                $hoja->setCellValueByColumnAndRow(3, 4, "Categoría");
                $hoja->setCellValueByColumnAndRow(4, 4, $nombre_categoria);

                //Cabezote de la tabla
                $hoja->setCellValueByColumnAndRow(1, 6, "Código Propuesta");
                $hoja->setCellValueByColumnAndRow(2, 6, "Propuesta Nombre");
                $hoja->setCellValueByColumnAndRow(3, 6, "Tipo Participante");
                $hoja->setCellValueByColumnAndRow(4, 6, "Tipo Rol");
                $hoja->setCellValueByColumnAndRow(5, 6, "Rol");
                $hoja->setCellValueByColumnAndRow(6, 6, "¿Representante?");
                $hoja->setCellValueByColumnAndRow(7, 6, "Tipo de documento");
                $hoja->setCellValueByColumnAndRow(8, 6, "Número de documento");
                $hoja->setCellValueByColumnAndRow(9, 6, "Nombres y Apellidos");
                $hoja->setCellValueByColumnAndRow(10, 6, "Fecha Nacimiento");
                $hoja->setCellValueByColumnAndRow(11, 6, "Sexo");
                $hoja->setCellValueByColumnAndRow(12, 6, "Dir. Residencia");
                $hoja->setCellValueByColumnAndRow(13, 6, "Ciudad de residencia");
                $hoja->setCellValueByColumnAndRow(14, 6, "Localidad Residencia");
                $hoja->setCellValueByColumnAndRow(15, 6, "Upz Residencia");
                $hoja->setCellValueByColumnAndRow(16, 6, "Barrio Residencia");
                $hoja->setCellValueByColumnAndRow(17, 6, "Estrato");
                $hoja->setCellValueByColumnAndRow(18, 6, "Correo electrónico");
                $hoja->setCellValueByColumnAndRow(19, 6, "Tel Fijo");
                $hoja->setCellValueByColumnAndRow(20, 6, "Tel Celular");

                //Registros de la base de datos
                $fila = 7;
                foreach ($convocatorias as $convocatoria) {

                    $hoja->setCellValueByColumnAndRow(1, $fila, $convocatoria->codigo);
                    $hoja->setCellValueByColumnAndRow(2, $fila, $convocatoria->nombre_propuesta);
                    $hoja->setCellValueByColumnAndRow(3, $fila, $convocatoria->tipo_participante);
                    $hoja->setCellValueByColumnAndRow(4, $fila, $convocatoria->tipo_rol);
                    $hoja->setCellValueByColumnAndRow(5, $fila, $convocatoria->rol);
                    $value_representante = "No";
                    if ($convocatoria->representante) {
                        $value_representante = "Sí";
                    }
                    $hoja->setCellValueByColumnAndRow(6, $fila, $value_representante);
                    $hoja->setCellValueByColumnAndRow(7, $fila, $convocatoria->tipo_documento);
                    $hoja->setCellValueByColumnAndRow(8, $fila, $convocatoria->numero_documento);
                    $hoja->setCellValueByColumnAndRow(9, $fila, $convocatoria->primer_nombre . " " . $convocatoria->segundo_nombre . " " . $convocatoria->primer_apellido . " " . $convocatoria->segundo_apellido);
                    $hoja->setCellValueByColumnAndRow(10, $fila, $convocatoria->fecha_nacimiento);
                    $hoja->setCellValueByColumnAndRow(11, $fila, $convocatoria->sexo);
                    $hoja->setCellValueByColumnAndRow(12, $fila, $convocatoria->direccion_residencia);
                    $hoja->setCellValueByColumnAndRow(13, $fila, $convocatoria->ciudad_residencia);
                    $hoja->setCellValueByColumnAndRow(14, $fila, $convocatoria->localidad_residencia);
                    $hoja->setCellValueByColumnAndRow(15, $fila, $convocatoria->upz_residencia);
                    $hoja->setCellValueByColumnAndRow(16, $fila, $convocatoria->barrio_residencia);
                    $hoja->setCellValueByColumnAndRow(17, $fila, $convocatoria->estrato);
                    $hoja->setCellValueByColumnAndRow(18, $fila, $convocatoria->correo_electronico);
                    $hoja->setCellValueByColumnAndRow(19, $fila, $convocatoria->numero_telefono);
                    $hoja->setCellValueByColumnAndRow(20, $fila, $convocatoria->numero_celular);
                    $fila++;
                }
            } else {
                //Cabezote de la tabla
                $hoja->setCellValueByColumnAndRow(1, 6, "Convocatoria");
                $hoja->setCellValueByColumnAndRow(2, 6, "Categoría");
                $hoja->setCellValueByColumnAndRow(3, 6, "Código Propuesta");
                $hoja->setCellValueByColumnAndRow(4, 6, "Propuesta Nombre");
                $hoja->setCellValueByColumnAndRow(5, 6, "Tipo Participante");
                $hoja->setCellValueByColumnAndRow(6, 6, "Tipo Rol");
                $hoja->setCellValueByColumnAndRow(7, 6, "Rol");
                $hoja->setCellValueByColumnAndRow(8, 6, "¿Representante?");
                $hoja->setCellValueByColumnAndRow(9, 6, "Tipo de documento");
                $hoja->setCellValueByColumnAndRow(10, 6, "Número de documento");
                $hoja->setCellValueByColumnAndRow(11, 6, "Nombres y Apellidos");
                $hoja->setCellValueByColumnAndRow(12, 6, "Fecha Nacimiento");
                $hoja->setCellValueByColumnAndRow(13, 6, "Sexo");
                $hoja->setCellValueByColumnAndRow(14, 6, "Dir. Residencia");
                $hoja->setCellValueByColumnAndRow(15, 6, "Ciudad de residencia");
                $hoja->setCellValueByColumnAndRow(16, 6, "Localidad Residencia");
                $hoja->setCellValueByColumnAndRow(17, 6, "Upz Residencia");
                $hoja->setCellValueByColumnAndRow(18, 6, "Barrio Residencia");
                $hoja->setCellValueByColumnAndRow(19, 6, "Estrato");
                $hoja->setCellValueByColumnAndRow(20, 6, "Correo electrónico");
                $hoja->setCellValueByColumnAndRow(21, 6, "Tel Fijo");
                $hoja->setCellValueByColumnAndRow(22, 6, "Tel Celular");

                //Registros de la base de datos
                $fila = 7;
                foreach ($convocatorias as $convocatoria) {

                    $hoja->setCellValueByColumnAndRow(1, $fila, $convocatoria->convocatoria);
                    $hoja->setCellValueByColumnAndRow(2, $fila, $convocatoria->categoria);

                    $hoja->setCellValueByColumnAndRow(3, $fila, $convocatoria->codigo);
                    $hoja->setCellValueByColumnAndRow(4, $fila, $convocatoria->nombre_propuesta);
                    $hoja->setCellValueByColumnAndRow(5, $fila, $convocatoria->tipo_participante);
                    $hoja->setCellValueByColumnAndRow(6, $fila, $convocatoria->tipo_rol);
                    $hoja->setCellValueByColumnAndRow(7, $fila, $convocatoria->rol);
                    $value_representante = "No";
                    if ($convocatoria->representante) {
                        $value_representante = "Sí";
                    }
                    $hoja->setCellValueByColumnAndRow(8, $fila, $value_representante);
                    $hoja->setCellValueByColumnAndRow(9, $fila, $convocatoria->tipo_documento);
                    $hoja->setCellValueByColumnAndRow(10, $fila, $convocatoria->numero_documento);
                    $hoja->setCellValueByColumnAndRow(11, $fila, $convocatoria->primer_nombre . " " . $convocatoria->segundo_nombre . " " . $convocatoria->primer_apellido . " " . $convocatoria->segundo_apellido);
                    $hoja->setCellValueByColumnAndRow(12, $fila, $convocatoria->fecha_nacimiento);
                    $hoja->setCellValueByColumnAndRow(13, $fila, $convocatoria->sexo);
                    $hoja->setCellValueByColumnAndRow(14, $fila, $convocatoria->direccion_residencia);
                    $hoja->setCellValueByColumnAndRow(15, $fila, $convocatoria->ciudad_residencia);
                    $hoja->setCellValueByColumnAndRow(16, $fila, $convocatoria->localidad_residencia);
                    $hoja->setCellValueByColumnAndRow(17, $fila, $convocatoria->upz_residencia);
                    $hoja->setCellValueByColumnAndRow(18, $fila, $convocatoria->barrio_residencia);
                    $hoja->setCellValueByColumnAndRow(19, $fila, $convocatoria->estrato);
                    $hoja->setCellValueByColumnAndRow(20, $fila, $convocatoria->correo_electronico);
                    $hoja->setCellValueByColumnAndRow(21, $fila, $convocatoria->numero_telefono);
                    $hoja->setCellValueByColumnAndRow(22, $fila, $convocatoria->numero_celular);
                    $fila++;
                }
            }



            $nombreDelDocumento = "listado_entidades_convocatorias_listado_contratistas_" . $entidad->id . "_" . $anio . ".xlsx";

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
            $logger->error('"token":"{token}","user":"{user}","message":"Token caduco en el metodo reporte_listado_entidades_convocatorias_listado_jurados_xls al generar el reporte listado de la propuesta (' . $request->getPut('id') . ')', ['user' => "", 'token' => $request->getPut('token')]);
            $logger->close();
            echo "error_token";
        }
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias           
        $logger->error('"token":"{token}","user":"{user}","message":"Error metodo reporte_listado_entidades_convocatorias_listado_jurados_xls al generar el reporte listado de la propuesta (' . $request->getPut('id') . ')' . $ex->getMessage() . '"', ['user' => "", 'token' => $request->getPut('token')]);
        $logger->close();
        echo "error_metodo";
    }
});

$app->post('/reporte_listado_entidades_convocatorias_no_inscritas', function () use ($app, $config, $logger) {

//Instancio los objetos que se van a manejar
    $request = new Request();
    $tokens = new Tokens();

    try {

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPut('token'));

        //Registro la accion en el log de convocatorias
        $logger->info('"token":"{token}","user":"{user}","message":"Ingresa al metodo reporte_listado_entidades_convocatorias_listado_jurados para generar reporte de listado de inscripcion de la propuesta (' . $request->getPut('id') . ')"', ['user' => '', 'token' => $request->getPut('token')]);

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

            //Consulto lo necesario
            $user_current = json_decode($token_actual->user_current, true);
            $entidad = Entidades::findFirst($request->getPut('entidad'));

            $html_propuestas = "";

            //Genero reporte propuestas por estado
            $sql_convocatorias = "
                        SELECT
                            p.nombre AS propuesta,
                            CONCAT(par.primer_nombre,' ',par.segundo_nombre,' ',par.primer_apellido,' ',par.segundo_apellido) AS participante,
                            u.username AS usuario_registro,
                            par.numero_documento,
                            par.numero_celular,
                            par.numero_celular_tercero,
                            par.numero_telefono,
                            par.correo_electronico
                        FROM Propuestas AS p 
                            INNER JOIN Participantes AS par ON par.id=p.participante                            
                            INNER JOIN Usuarios AS u ON u.id=p.creado_por
                            LEFT JOIN Tiposdocumentos AS td ON td.id=par.tipo_documento                            
                        WHERE p.convocatoria=" . $request->getPut('convocatoria') . " AND p.estado=7";

            $convocatorias = $app->modelsManager->executeQuery($sql_convocatorias);

            foreach ($convocatorias as $convocatoria) {
                $html_propuestas = $html_propuestas . "<tr>";
                $html_propuestas = $html_propuestas . "<td>" . $convocatoria->numero_documento . "</td>";
                $html_propuestas = $html_propuestas . "<td>" . $convocatoria->propuesta . "</td>";
                $html_propuestas = $html_propuestas . "<td>" . $convocatoria->participante . "</td>";
                $html_propuestas = $html_propuestas . "<td>" . $convocatoria->usuario_registro . "</td>";
                $html_propuestas = $html_propuestas . "<td>" . $convocatoria->numero_celular . "</td>";
                $html_propuestas = $html_propuestas . "<td>" . $convocatoria->numero_celular_tercero . "</td>";
                $html_propuestas = $html_propuestas . "<td>" . $convocatoria->numero_telefono . "</td>";
                $html_propuestas = $html_propuestas . "<td>" . $convocatoria->correo_electronico . "</td>";
                $html_propuestas = $html_propuestas . "</tr>";
            }

            //Consulto la convocatoria
            $convocatoria = Convocatorias::findFirst($request->getPut('convocatoria'));
            //Si la convocatoria seleccionada es categoria, debo invertir los nombres la convocatoria con la categoria
            $nombre_convocatoria = $convocatoria->nombre;
            $nombre_categoria = "";
            if ($convocatoria->convocatoria_padre_categoria > 0) {
                $nombre_convocatoria = $convocatoria->getConvocatorias()->nombre;
                $nombre_categoria = $convocatoria->nombre;
            }

            $html = '<table border="1" cellpadding="2" cellspacing="2" nobr="true">
                    <tr>
                        <td colspan="8" align="center">  Listado de propuestas Guardada - No Inscrita   </td>
                    </tr>                    
                    <tr>
                        <td colspan="8" align="center"> Fecha de corte ' . date("Y-m-d H:i:s") . '</td>
                    </tr>
                    <tr>
                        <td colspan="4">Año: ' . $request->getPut('anio') . '</td>
                        <td colspan="4">Entidad: ' . $entidad->descripcion . '</td>
                    </tr>                                    
                    <tr>
                        <td colspan="4">Convocatoria: ' . $nombre_convocatoria . '</td>
                        <td colspan="4">Categoría: ' . $nombre_categoria . '</td>
                    </tr>                                    
                    <tr style="background-color:#BDBDBD;color:#OOOOOO;">
                        <td align="center">Número de Documento</td>                        
                        <td align="center">Participante</td>      
                        <td align="center">Propuesta</td>
                        <td align="center">Usuario de registro</td>
                        <td align="center">Número celular</td>
                        <td align="center">Número celular tercero</td>                        
                        <td align="center">Teléfono fijo</td>         
                        <td align="center">Correo electrónico</td>
                    </tr> 
                    ' . $html_propuestas . '
                </table>';

            $logger->info('"token":"{token}","user":"{user}","message":"Se genero el reporte de inscripcion de la propuesta (' . $request->getPut('id') . ')', ['user' => $user_current["username"], 'token' => $request->getPut('token')]);
            $logger->close();
            echo $html;
        } else {
            //Registro la accion en el log de convocatorias           
            $logger->error('"token":"{token}","user":"{user}","message":"Token caduco en el metodo reporte_listado_propuesta_rechazados_habilitados al generar el reporte listado de la propuesta (' . $request->getPut('id') . ')', ['user' => "", 'token' => $request->getPut('token')]);
            $logger->close();
            echo "error_token";
        }
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias           
        $logger->error('"token":"{token}","user":"{user}","message":"Error metodo reporte_listado_propuesta_rechazados_habilitados al generar el reporte listado de la propuesta (' . $request->getPut('id') . ')' . $ex->getMessage() . '"', ['user' => "", 'token' => $request->getPut('token')]);
        $logger->close();
        echo "error_metodo";
    }
});

$app->post('/reporte_persona_natural', function () use ($app, $config, $logger) {

//Instancio los objetos que se van a manejar
    $request = new Request();
    $tokens = new Tokens();

    try {

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPut('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

            //Consulto el usuario
            $user_current = json_decode($token_actual->user_current, true);

            $html_propuestas = "";
            $html_propuestas_ganadoras = "";
            $html_propuestas_contratistas = "";
            $html_propuestas_jurados_seleccionados = "";
            $html_propuestas_jurados_proceso = "";

            //Genero reporte de jurados seleccionados
            $sql_jurados_seleccionado = "
                        SELECT 
                                en.nombre AS entidad,
                                cp.nombre AS convocatoria,
                                c.nombre AS categoria,	
                                par.tipo AS rol_participante,	
                                jp.rol AS rol_jurado,
                                par.numero_documento,
                                concat(par.primer_nombre, ' ' ,par.segundo_nombre, ' ' ,par.primer_apellido, ' ' ,par.segundo_apellido ) AS participante,
                                pro.codigo AS codigo_propuesta,
                                e.nombre AS estado_de_la_postulacion
                        FROM Juradospostulados as jp
                                INNER JOIN Evaluadores ev ON jp.id=ev.juradopostulado 
                                INNER JOIN Propuestas AS pro ON jp.propuesta = pro.id
                                INNER JOIN Participantes par on pro.participante = par.id
                                INNER JOIN Convocatorias AS c ON jp.convocatoria = c.id
                                INNER JOIN Entidades AS en ON en.id=c.entidad
                                LEFT JOIN Convocatorias as cp ON c.convocatoria_padre_categoria = cp.id
                                LEFT JOIN Estados e ON jp.estado=e.id
                        WHERE 	
                                jp.active=true AND
                            ev.active = true AND	                            
                            REPLACE(REPLACE(TRIM(par.numero_documento),'.',''),' ', '')= REPLACE(REPLACE(TRIM('" . $request->getPut('nd') . "'),'.',''),' ', '')
                        ";

            $jurados_seleccionados = $app->modelsManager->executeQuery($sql_jurados_seleccionado);

            foreach ($jurados_seleccionados as $jurado) {
                if ($jurado->convocatoria == "") {
                    $jurado->convocatoria = $jurado->categoria;
                    $jurado->categoria = "";
                }

                if ($jurado->categoria != "") {
                    $jurado->categoria = "- " . $jurado->categoria;
                }

                $html_propuestas_jurados_seleccionados = $html_propuestas_jurados_seleccionados . "<tr>";
                $html_propuestas_jurados_seleccionados = $html_propuestas_jurados_seleccionados . "<td>" . $jurado->entidad . "</td>";
                $html_propuestas_jurados_seleccionados = $html_propuestas_jurados_seleccionados . "<td>" . $jurado->convocatoria . " " . $jurado->categoria . "</td>";
                $html_propuestas_jurados_seleccionados = $html_propuestas_jurados_seleccionados . '<td>Jurado</td>';
                $html_propuestas_jurados_seleccionados = $html_propuestas_jurados_seleccionados . '<td colspan="3">' . $jurado->participante . '</td>';
                $html_propuestas_jurados_seleccionados = $html_propuestas_jurados_seleccionados . '<td>Seleccionado - ' . $jurado->rol_jurado . '</td>';
                $html_propuestas_jurados_seleccionados = $html_propuestas_jurados_seleccionados . "</tr>";
            }

            //Genero reporte de jurados proceso
            $sql_jurados_proceso = "
                        SELECT 
                            en.nombre AS entidad,
                            cp.nombre AS convocatoria,
                            c.nombre AS categoria,	
                            par.tipo AS rol_participante,	
                            jp.rol AS rol_jurado,
                            par.numero_documento,
                            concat(par.primer_nombre, ' ' ,par.segundo_nombre, ' ' ,par.primer_apellido, ' ' ,par.segundo_apellido ) AS participante,
                            pro.codigo AS codigo_propuesta,
                            e.nombre AS estado_de_la_postulacion
                        FROM Juradospostulados as jp
                            INNER JOIN Propuestas AS pro ON jp.propuesta = pro.id
                            INNER JOIN Participantes AS par ON pro.participante = par.id
                            INNER JOIN Convocatorias AS c ON jp.convocatoria = c.id
                            INNER JOIN Entidades AS en ON en.id=c.entidad
                            LEFT JOIN Convocatorias AS cp ON c.convocatoria_padre_categoria = cp.id
                            LEFT JOIN Estados AS e ON jp.estado=e.id
                        WHERE 
                            jp.active=TRUE AND  
                            REPLACE(REPLACE(TRIM(par.numero_documento),'.',''),' ', '')=REPLACE(REPLACE(TRIM('" . $request->getPut('nd') . "'),'.',''),' ', '')
                        ORDER BY 1,2,3,4,5,6,7,8";

            $jurados_procesos = $app->modelsManager->executeQuery($sql_jurados_proceso);

            foreach ($jurados_procesos as $jurado) {
                if ($jurado->convocatoria == "") {
                    $jurado->convocatoria = $jurado->categoria;
                    $jurado->categoria = "";
                }

                if ($jurado->categoria != "") {
                    $jurado->categoria = "- " . $jurado->categoria;
                }

                $html_propuestas_jurados_proceso = $html_propuestas_jurados_proceso . "<tr>";
                $html_propuestas_jurados_proceso = $html_propuestas_jurados_proceso . "<td>" . $jurado->entidad . "</td>";
                $html_propuestas_jurados_proceso = $html_propuestas_jurados_proceso . "<td>" . $jurado->convocatoria . " " . $jurado->categoria . "</td>";
                $html_propuestas_jurados_proceso = $html_propuestas_jurados_proceso . '<td>Jurado</td>';
                $html_propuestas_jurados_proceso = $html_propuestas_jurados_proceso . '<td colspan="3">' . $jurado->participante . '</td>';
                $html_propuestas_jurados_proceso = $html_propuestas_jurados_proceso . '<td>' . $jurado->estado_de_la_postulacion . '</td>';
                $html_propuestas_jurados_proceso = $html_propuestas_jurados_proceso . "</tr>";
            }

            //Genero reporte personas naturales
            $sql_pn = "
                        SELECT 
                                e.nombre AS entidad,
                                vwp.convocatoria,
                                vwp.id_convocatoria,
                                vwp.codigo,
                                vwp.nombre_propuesta,
                                vwp.tipo_participante,
                                vwp.representante,
                                vwp.tipo_rol,
                                vwp.rol,
                                vwp.primer_nombre,
                                vwp.segundo_nombre,
                                vwp.primer_apellido,
                                vwp.segundo_apellido,
                                vwp.estado_propuesta,
                                es.id AS id_estado_convocatoria,
                                es.nombre AS estado_convocatoria
                        FROM Viewparticipantes AS vwp                                
                        INNER JOIN Convocatorias AS c ON c.id=vwp.id_convocatoria
                        INNER JOIN Entidades AS e ON e.id=c.entidad
                        LEFT JOIN Estados AS es ON c.estado=es.id
                        WHERE vwp.tipo_participante <> 'Jurados' AND REPLACE(REPLACE(TRIM(vwp.numero_documento),'.',''),' ', '')=REPLACE(REPLACE(TRIM('" . $request->getPut('nd') . "'),'.',''),' ', '')
                        ";

            $personas_naturales = $app->modelsManager->executeQuery($sql_pn);


            foreach ($personas_naturales as $pn) {

                //Consulto la convocatoria
                $convocatoria = Convocatorias::findFirst($pn->id_convocatoria);

                //Si la convocatoria seleccionada es categoria, debo invertir los nombres la convocatoria con la categoria
                $nombre_convocatoria = $convocatoria->nombre;
                $nombre_categoria = "";
                $anio_convocatoria = $convocatoria->anio;
                if ($convocatoria->convocatoria_padre_categoria > 0) {
                    $nombre_convocatoria = $convocatoria->getConvocatorias()->nombre;
                    $nombre_categoria = " - " . $convocatoria->nombre;
                    $anio_convocatoria = $convocatoria->getConvocatorias()->anio;
                }


                if ($anio_convocatoria == $request->getPut('anio')) {
                    $estado_convocatoria = $pn->estado_convocatoria;

                    if ($pn->id_estado_convocatoria == 5) {
                        $fecha_actual = strtotime(date("Y-m-d H:i:s"), time());
                        $fecha_cierre_real = Convocatoriascronogramas::findFirst("convocatoria=" . $pn->id_convocatoria . " AND tipo_evento = 12");
                        $fecha_cierre = strtotime($fecha_cierre_real->fecha_fin, time());
                        if ($fecha_actual > $fecha_cierre) {
                            $estado_convocatoria = "Publicada Cerrada";
                        } else {
                            $fecha_apertura_real = Convocatoriascronogramas::findFirst("convocatoria=" . $pn->id_convocatoria . " AND tipo_evento = 11");
                            $fecha_apertura = strtotime($fecha_apertura_real->fecha_fin, time());
                            if ($fecha_actual > $fecha_apertura) {
                                $estado_convocatoria = "Publicada Abierta";
                            }
                        }
                    }


                    if ($pn->estado_propuesta == "Ganadora") {
                        $html_propuestas_ganadoras = $html_propuestas_ganadoras . "<tr>";
                        $html_propuestas_ganadoras = $html_propuestas_ganadoras . "<td>" . $pn->entidad . "</td>";
                        $html_propuestas_ganadoras = $html_propuestas_ganadoras . "<td>" . $nombre_convocatoria . "" . $nombre_categoria . "</td>";
                        $html_propuestas_ganadoras = $html_propuestas_ganadoras . "<td>" . $estado_convocatoria . "</td>";
                        $html_propuestas_ganadoras = $html_propuestas_ganadoras . "<td>" . $pn->tipo_rol . "</td>";
                        $html_propuestas_ganadoras = $html_propuestas_ganadoras . "<td>" . $pn->primer_nombre . " " . $pn->segundo_nombre . " " . $pn->primer_apellido . " " . $pn->segundo_apellido . "</td>";
                        $html_propuestas_ganadoras = $html_propuestas_ganadoras . "<td>" . $pn->codigo . "</td>";
                        $html_propuestas_ganadoras = $html_propuestas_ganadoras . "<td>" . $pn->estado_propuesta . "</td>";
                        $html_propuestas_ganadoras = $html_propuestas_ganadoras . "</tr>";
                    } else {
                        $html_propuestas = $html_propuestas . "<tr>";
                        $html_propuestas = $html_propuestas . "<td>" . $pn->entidad . "</td>";
                        $html_propuestas = $html_propuestas . "<td>" . $nombre_convocatoria . "" . $nombre_categoria . "</td>";
                        $html_propuestas = $html_propuestas . "<td>" . $estado_convocatoria . "</td>";
                        $html_propuestas = $html_propuestas . "<td>" . $pn->tipo_rol . "</td>";
                        $html_propuestas = $html_propuestas . "<td>" . $pn->primer_nombre . " " . $pn->segundo_nombre . " " . $pn->primer_apellido . " " . $pn->segundo_apellido . "</td>";
                        $html_propuestas = $html_propuestas . "<td>" . $pn->codigo . "</td>";
                        $html_propuestas = $html_propuestas . "<td>" . $pn->estado_propuesta . "</td>";
                        $html_propuestas = $html_propuestas . "</tr>";
                    }
                }
            }

            //Consulto si es contratista            
            $sql_contratistas = "
                SELECT 
                        e.nombre AS entidad,
                        concat(ec.primer_nombre,' ',ec.segundo_nombre,' ',ec.primer_apellido,' ',ec.segundo_apellido) AS contratista,
                        ec.observaciones,
                        ec.fecha_creacion
                FROM Entidadescontratistas AS ec
                INNER JOIN Entidades AS e ON e.id=ec.entidad
                WHERE ec.active=TRUE AND REPLACE(REPLACE(TRIM(ec.numero_documento),'.',''),' ', '')=REPLACE(REPLACE(TRIM('" . $request->getPut('nd') . "'),'.',''),' ', '')";

            $contratistas = $app->modelsManager->executeQuery($sql_contratistas);

            foreach ($contratistas as $contratista) {
                $html_propuestas_contratistas = $html_propuestas_contratistas . "<tr>";
                $html_propuestas_contratistas = $html_propuestas_contratistas . "<td>" . $contratista->entidad . "</td>";
                $html_propuestas_contratistas = $html_propuestas_contratistas . '<td colspan="2">' . $contratista->contratista . " " . $convocatoria->segundo_nombre . " " . $convocatoria->primer_apellido . " " . $convocatoria->segundo_apellido . '</td>';
                $html_propuestas_contratistas = $html_propuestas_contratistas . '<td colspan="3">' . $contratista->observaciones . '</td>';
                $html_propuestas_contratistas = $html_propuestas_contratistas . '<td>' . $contratista->fecha_creacion . '</td>';
                $html_propuestas_contratistas = $html_propuestas_contratistas . "</tr>";
            }

            //Genero reporte de jurados seleccionados
            $sql_ganadores_anios_anteriores = "
                        SELECT 
                                ga.*
                        FROM Ganadoresantes2020 as ga                                
                        WHERE 	
                                ga.active=true AND                               
                                REPLACE(REPLACE(TRIM(ga.numero_documento),'.',''),' ', '') IN (REPLACE(REPLACE(TRIM('" . $request->getPut('nd') . "'),'.',''),' ', ''))                            
                        ORDER BY ga.anio DESC
                        ";

            $ganadores_anios_anteriores = $app->modelsManager->executeQuery($sql_ganadores_anios_anteriores);

            foreach ($ganadores_anios_anteriores as $ganador_anio_anterior) {
                $html_ganadoras_anios_anteriores = $html_ganadoras_anios_anteriores . "<tr class='tr_ganador_anio_anterior'>";
                $html_ganadoras_anios_anteriores = $html_ganadoras_anios_anteriores . "<td>" . $ganador_anio_anterior->anio . "</td>";
                $html_ganadoras_anios_anteriores = $html_ganadoras_anios_anteriores . "<td>" . $ganador_anio_anterior->entidad . "</td>";
                $html_ganadoras_anios_anteriores = $html_ganadoras_anios_anteriores . "<td>" . $ganador_anio_anterior->convocatoria . " - " . $ganador_anio_anterior->categoria . "</td>";
                $html_ganadoras_anios_anteriores = $html_ganadoras_anios_anteriores . "<td>Adjudicada</td>";
                $html_ganadoras_anios_anteriores = $html_ganadoras_anios_anteriores . "<td>" . $ganador_anio_anterior->codigo_propuesta . " - " . $ganador_anio_anterior->estado_propuesta . " - " . $ganador_anio_anterior->nombre_propuesta . "</td>";
                $html_ganadoras_anios_anteriores = $html_ganadoras_anios_anteriores . "<td>" . $ganador_anio_anterior->primer_nombre . " " . $ganador_anio_anterior->segundo_nombre . " " . $ganador_anio_anterior->primer_apellido . " " . $ganador_anio_anterior->segundo_apellido . "</td>";
                $html_ganadoras_anios_anteriores = $html_ganadoras_anios_anteriores . "<td>" . $ganador_anio_anterior->tipo_participante . " - " . $ganador_anio_anterior->tipo_rol . "</td>";
                $html_ganadoras_anios_anteriores = $html_ganadoras_anios_anteriores . "</tr>";
            }


            $html = '<table border="1" cellpadding="2" cellspacing="2" nobr="true">
                    <tr>
                        <td colspan="7" align="center">PARTICIPACIÓN DE PERSONA NATURAL EN CONVOCATORIAS Y JURADOS</td>
                    </tr>                    
                    <tr>
                        <td colspan="7" align="center">Año: ' . $request->getPut('anio') . '</td>
                    </tr>                    
                    <tr>
                        <td colspan="7" align="center">Número de documento: ' . $request->getPut('nd') . '</td>
                    </tr>                    
                    <tr>
                        <td colspan="7" align="center"> Fecha de corte ' . date("Y-m-d H:i:s") . '</td>
                    </tr>
                    <tr>
                        <td colspan="7" align="center" style="background-color:#BDBDBD;color:#OOOOOO;font-weight:bold"> CONVOCATORIAS EN LAS QUE HA PRESENTADO PROPUESTA </td>
                    </tr>
                    <tr style="background-color:#D8D8D8;color:#OOOOOO;">
                        <td align="center">Entidad</td>
                        <td align="center">Convocatoria - Categoría</td>   
                        <td align="center">Estado Convocatoria</td>
                        <td align="center">Tipo Rol</td>                        
                        <td align="center">Participante</td>
                        <td align="center">Propuesta código</td>                                                                                                                   
                        <td align="center">Estado propuesta</td>                                                                                                                   
                    </tr>
                    ' . $html_propuestas . '
                    <tr>
                        <td colspan="7" align="center" style="background-color:#BDBDBD;color:#OOOOOO;font-weight:bold"> CONVOCATORIAS EN LAS QUE ESTA EN PROCESO PARA SER JURADO </td>
                    </tr>
                    <tr style="background-color:#D8D8D8;color:#OOOOOO;">
                        <td align="center">Entidad</td>
                        <td align="center">Convocatoria - Categoría</td>                        
                        <td align="center">Tipo Rol</td>                        
                        <td align="center" colspan="3">Participante</td>                        
                        <td align="center">Estado postulación</td>                                                                                                                   
                    </tr>
                    ' . $html_propuestas_jurados_proceso . '        
                    <tr>
                        <td colspan="7" align="center" style="background-color:#BDBDBD;color:#OOOOOO;font-weight:bold"> CONVOCATORIAS QUE HA GANADO </td>
                    </tr>
                    <tr style="background-color:#D8D8D8;color:#OOOOOO;">
                        <td align="center">Entidad</td>
                        <td align="center">Convocatoria - Categoría</td>   
                        <td align="center">Estado Convocatoria</td>
                        <td align="center">Tipo Rol</td>                        
                        <td align="center">Participante</td>
                        <td align="center">Propuesta código</td>                                                                                                                   
                        <td align="center">Estado propuesta</td>                                                                                                                   
                    </tr>
                    ' . $html_propuestas_ganadoras . '                          
                    <tr>
                        <td colspan="7" align="center" style="background-color:#BDBDBD;color:#OOOOOO;font-weight:bold"> CONVOCATORIAS EN LAS QUE HA SIDO JURADO </td>
                    </tr>
                    <tr style="background-color:#D8D8D8;color:#OOOOOO;">
                        <td align="center">Entidad</td>
                        <td align="center">Convocatoria - Categoría</td>                           
                        <td align="center">Tipo Rol</td>                        
                        <td align="center" colspan="3">Participante</td>                        
                        <td align="center">Estado postulación</td>                                                                                                                   
                    </tr>
                    ' . $html_propuestas_jurados_seleccionados . '                        
                    <tr>
                        <td colspan="7" align="center" style="background-color:#BDBDBD;color:#OOOOOO;font-weight:bold"> CONVOCATORIAS COMO GANADOR AÑOS ANTERIORES </td>
                    </tr>    
                    <tr style="background-color:#D8D8D8;color:#OOOOOO;">
                        <td align="center">Año</td>
                        <td align="center">Entidad</td>
                        <td align="center">Convocatoria - Categoría</td>     
                        <td align="center">Estado Convocatoria</td>
                        <td align="center">Propuesta</td>   
                        <td align="center">Participante</td>
                        <td align="center">Tipo participante - Tipo Rol</td>                                                                        
                    </tr>
                    ' . $html_ganadoras_anios_anteriores . '      
                    <tr>
                        <td colspan="7" align="center" style="background-color:#BDBDBD;color:#OOOOOO;font-weight:bold"> APARECE EN LA BASE DE DATOS DE CONTRATISTAS O FUNCIONARIOS </td>
                    </tr>
                    <tr style="background-color:#D8D8D8;color:#OOOOOO;">
                        <td align="center">Entidad</td>
                        <td align="center" colspan="2">Contratista</td>   
                        <td align="center" colspan="3">Observaciones</td>                                                
                        <td align="center">Fecha de cargue</td>                                                
                    </tr>
                    ' . $html_propuestas_contratistas . '    
                </table>';

            $logger->info('"token":"{token}","user":"{user}","message":"Se genero el reporte de la persona natural (' . $request->getPut('pn') . ')', ['user' => $user_current["username"], 'token' => $request->getPut('token')]);
            $logger->close();
            echo $html;
        } else {
            //Registro la accion en el log de convocatorias           
            $logger->error('"token":"{token}","user":"{user}","message":"Token caduco en el metodo reporte_persona_natural al generar el reporte de la persona natural (' . $request->getPut('nd') . ')', ['user' => "", 'token' => $request->getPut('token')]);
            $logger->close();
            echo "error_token";
        }
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias           
        $logger->error('"token":"{token}","user":"{user}","message":"Error metodo reporte_persona_natural al generar el reporte de la persona natural (' . $request->getPut('nd') . ')' . $ex->getMessage() . '"', ['user' => "", 'token' => $request->getPut('token')]);
        $logger->close();
        echo "error_metodo";
    }
});

$app->post('/reporte_listado_entidades_convocatorias_no_inscritas_xls', function () use ($app, $config, $logger) {

    //Instancio los objetos que se van a manejar
    $request = new Request();
    $tokens = new Tokens();

    try {
        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPut('token'));

        //Registro la accion en el log de convocatorias
        $logger->info('"token":"{token}","user":"{user}","message":"Ingresa al metodo reporte_listado_entidades_convocatorias_listado_jurados_xls para generar reporte de listado de inscripcion de la propuesta (' . $request->getPut('id') . ')"', ['user' => '', 'token' => $request->getPut('token')]);

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {


            require_once("../library/phpspreadsheet/autoload.php");

            $entidad = Entidades::findFirst($request->getPut('entidad'));
            $anio = $request->getPut('anio');

            //Genero reporte propuestas por estado
            $sql_convocatorias = "
                        SELECT     	
                            p.nombre AS propuesta,
                            CONCAT(par.primer_nombre,' ',par.segundo_nombre,' ',par.primer_apellido,' ',par.segundo_apellido) AS participante,
                            u.username AS usuario_registro,
                            par.numero_documento,
                            par.numero_celular,
                            par.numero_celular_tercero,
                            par.numero_telefono,
                            par.correo_electronico
                        FROM Propuestas AS p 
                            INNER JOIN Participantes AS par ON par.id=p.participante
                            LEFT JOIN Tiposdocumentos AS td ON td.id=par.tipo_documento
                            INNER JOIN Usuarios AS u ON u.id=p.creado_por
                        WHERE p.convocatoria=" . $request->getPut('convocatoria') . " AND p.estado=7";

            $convocatorias = $app->modelsManager->executeQuery($sql_convocatorias);

            $documento = new Spreadsheet();
            $documento
                    ->getProperties()
                    ->setCreator("SICON")
                    ->setLastModifiedBy('SICON') // última vez modificado por
                    ->setTitle('Propuestas Guardada - No Inscrita')
                    ->setSubject('SICON')
                    ->setDescription('Propuestas Guardada - No Inscrita')
                    ->setKeywords('SICON')
                    ->setCategory('La categoría');

            $hoja = $documento->getActiveSheet();
            $hoja->setTitle("Pro Guardada - No Inscrita");

            //Consulto la convocatoria
            $convocatoria = Convocatorias::findFirst($request->getPut('convocatoria'));
            //Si la convocatoria seleccionada es categoria, debo invertir los nombres la convocatoria con la categoria
            $nombre_convocatoria = $convocatoria->nombre;
            $nombre_categoria = "";
            if ($convocatoria->convocatoria_padre_categoria > 0) {
                $nombre_convocatoria = $convocatoria->getConvocatorias()->nombre;
                $nombre_categoria = $convocatoria->nombre;
            }

            //Cabezote de la tabla
            $hoja->setCellValueByColumnAndRow(1, 1, "Listado de propuestas Guardada - No Inscrita");

            //Cabezote de la tabla
            $hoja->setCellValueByColumnAndRow(1, 2, "Fecha de corte");
            $hoja->setCellValueByColumnAndRow(2, 2, date("Y-m-d H:i:s"));

            //Cabezote de la tabla
            $hoja->setCellValueByColumnAndRow(1, 3, "Año");
            $hoja->setCellValueByColumnAndRow(2, 3, $anio);
            $hoja->setCellValueByColumnAndRow(3, 3, "Entidad");
            $hoja->setCellValueByColumnAndRow(4, 3, $entidad->descripcion);

            //Convocatoria
            $hoja->setCellValueByColumnAndRow(1, 4, "Convocatoria");
            $hoja->setCellValueByColumnAndRow(2, 4, $nombre_convocatoria);
            $hoja->setCellValueByColumnAndRow(3, 4, "Categoría");
            $hoja->setCellValueByColumnAndRow(4, 4, $nombre_categoria);

            //Cabezote de la tabla
            $hoja->setCellValueByColumnAndRow(1, 5, "Número de Documento");
            $hoja->setCellValueByColumnAndRow(2, 5, "Participante");
            $hoja->setCellValueByColumnAndRow(3, 5, "Propuesta");
            $hoja->setCellValueByColumnAndRow(4, 5, "Usuario de registro");
            $hoja->setCellValueByColumnAndRow(5, 5, "Número celular");
            $hoja->setCellValueByColumnAndRow(6, 5, "Número celular tercero");
            $hoja->setCellValueByColumnAndRow(7, 5, "Teléfono fijo");
            $hoja->setCellValueByColumnAndRow(8, 5, "Correo electrónico");

            //Registros de la base de datos
            $fila = 6;
            foreach ($convocatorias as $convocatoria) {
                $hoja->setCellValueByColumnAndRow(1, $fila, $convocatoria->numero_documento);
                $hoja->setCellValueByColumnAndRow(2, $fila, $convocatoria->participante);
                $hoja->setCellValueByColumnAndRow(3, $fila, $convocatoria->propuesta);
                $hoja->setCellValueByColumnAndRow(4, $fila, $convocatoria->usuario_registro);
                $hoja->setCellValueByColumnAndRow(5, $fila, $convocatoria->numero_celular);
                $hoja->setCellValueByColumnAndRow(6, $fila, $convocatoria->numero_celular_tercero);
                $hoja->setCellValueByColumnAndRow(7, $fila, $convocatoria->numero_telefono);
                $hoja->setCellValueByColumnAndRow(8, $fila, $convocatoria->correo_electronico);
                $fila++;
            }


            $nombreDelDocumento = "listado_entidades_convocatorias_listado_no_inscritas_" . $entidad->id . "_" . $anio . ".xlsx";

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
            $logger->error('"token":"{token}","user":"{user}","message":"Token caduco en el metodo reporte_listado_entidades_convocatorias_listado_jurados_xls al generar el reporte listado de la propuesta (' . $request->getPut('id') . ')', ['user' => "", 'token' => $request->getPut('token')]);
            $logger->close();
            echo "error_token";
        }
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias           
        $logger->error('"token":"{token}","user":"{user}","message":"Error metodo reporte_listado_entidades_convocatorias_listado_jurados_xls al generar el reporte listado de la propuesta (' . $request->getPut('id') . ')' . $ex->getMessage() . '"', ['user' => "", 'token' => $request->getPut('token')]);
        $logger->close();
        echo "error_metodo";
    }
});

$app->post('/reporte_convocatorias_listado_inscritas_pdac_xls', function () use ($app, $config, $logger) {

    //Instancio los objetos que se van a manejar
    $request = new Request();
    $tokens = new Tokens();

    try {
        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPut('token'));

        //Registro la accion en el log de convocatorias
        $logger->info('"token":"{token}","user":"{user}","message":"Ingresa al metodo reporte_listado_entidades_convocatorias_listado_jurados_xls para generar reporte de listado de inscripcion de la propuesta (' . $request->getPut('id') . ')"', ['user' => '', 'token' => $request->getPut('token')]);

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {


            require_once("../library/phpspreadsheet/autoload.php");

            $entidad = Entidades::findFirst($request->getPut('entidad'));
            $anio = $request->getPut('anio');

            //Genero propuestas
            $sql_convocatorias = "                           	
                            SELECT     		
                                    vcon.anio,
                                    vcon.convocatoria,
                                    vcon.categoria,
                                    est.nombre as estado,
                                    p.nombre AS nombre_propuesta,
                                    p.codigo,
                                    p.primera_vez_pdac,
                                    p.alianza_sectorial,
                                    p.relacion_plan,
                                    p.linea_estrategica,
                                    p.area,
                                    p.trayectoria_entidad,
                                    p.problema_necesidad,
                                    p.diagnostico_problema,
                                    p.justificacion,
                                    p.atencedente,
                                    p.metodologia,
                                    p.impacto,
                                    p.mecanismos_cualitativa,
                                    p.mecanismos_cuantitativa,
                                    p.porque_medio,
                                    p.ejecucion_menores_edad,    
                                    p.proyeccion_reconocimiento,
                                    p.impacto_proyecto,
                                    p.alcance_territorial,
                                    td.nombre as tipo_identificacion,
                                    par.numero_documento,
                                    CONCAT(par.primer_nombre,' ',par.segundo_nombre,' ',par.primer_apellido,' ',par.segundo_apellido) AS participante,
                                    u.username AS usuario_registro,    
                                    par.numero_celular,                                    
                                    par.numero_telefono,
                                    par.correo_electronico
                                FROM Propuestas AS p
                                        INNER JOIN Viewconvocatorias AS vcon ON vcon.id_categoria=p.convocatoria
                                        INNER JOIN Estados AS est ON est.id=p.estado
                                    INNER JOIN Participantes AS par ON par.id=p.participante
                                    LEFT JOIN Tiposdocumentos AS td ON td.id=par.tipo_documento
                                    INNER JOIN Usuarios AS u ON u.id=p.creado_por
                                WHERE p.convocatoria=" . $request->getPut('convocatoria') . " AND p.estado not in (7,20)";

            $convocatorias = $app->modelsManager->executeQuery($sql_convocatorias);

            $documento = new Spreadsheet();
            $documento
                    ->getProperties()
                    ->setCreator("SICON")
                    ->setLastModifiedBy('SICON') // última vez modificado por
                    ->setTitle('Propuestas Inscritas PDAC - ' . $anio)
                    ->setSubject('SICON')
                    ->setDescription('Propuestas Inscritas PDAC')
                    ->setKeywords('SICON')
                    ->setCategory('La categoría');

            $hoja = $documento->getActiveSheet();
            $hoja->setTitle("Propuestas Inscritas PDAC");

            //Consulto la convocatoria
            $convocatoria = Convocatorias::findFirst($request->getPut('convocatoria'));
            //Si la convocatoria seleccionada es categoria, debo invertir los nombres la convocatoria con la categoria
            $nombre_convocatoria = $convocatoria->nombre;
            $nombre_categoria = "";
            if ($convocatoria->convocatoria_padre_categoria > 0) {
                $nombre_convocatoria = $convocatoria->getConvocatorias()->nombre;
                $nombre_categoria = $convocatoria->nombre;
            }

            //Cabezote de la tabla
            $hoja->setCellValueByColumnAndRow(1, 1, "Propuestas Inscritas PDAC");

            //Cabezote de la tabla
            $hoja->setCellValueByColumnAndRow(1, 2, "Fecha de corte");
            $hoja->setCellValueByColumnAndRow(2, 2, date("Y-m-d H:i:s"));

            //Cabezote de la tabla
            $hoja->setCellValueByColumnAndRow(1, 3, "Año");
            $hoja->setCellValueByColumnAndRow(2, 3, $anio);
            $hoja->setCellValueByColumnAndRow(3, 3, "Entidad");
            $hoja->setCellValueByColumnAndRow(4, 3, $entidad->descripcion);

            //Convocatoria
            $hoja->setCellValueByColumnAndRow(1, 4, "Convocatoria");
            $hoja->setCellValueByColumnAndRow(2, 4, $nombre_convocatoria);
            $hoja->setCellValueByColumnAndRow(3, 4, "Categoría");
            $hoja->setCellValueByColumnAndRow(4, 4, $nombre_categoria);

            //Cabezote de la tabla
            $hoja->setCellValueByColumnAndRow(1, 5, "Año");
            $hoja->setCellValueByColumnAndRow(2, 5, "Convocatoria");
            $hoja->setCellValueByColumnAndRow(3, 5, "Categoría");
            $hoja->setCellValueByColumnAndRow(4, 5, "Estado");
            $hoja->setCellValueByColumnAndRow(5, 5, "Nombre de la propuesta");
            $hoja->setCellValueByColumnAndRow(6, 5, "Código");
            $hoja->setCellValueByColumnAndRow(7, 5, "¿Es la primera vez que la propuesta se presenta al PDAC?");
            $hoja->setCellValueByColumnAndRow(8, 5, "¿El proyecto es el resultado de una alianza sectorial?");
            $hoja->setCellValueByColumnAndRow(9, 5, "Relación del proyecto con el Plan de Desarrollo de Bogotá");
            $hoja->setCellValueByColumnAndRow(10, 5, "Línea estratégica del proyecto");
            $hoja->setCellValueByColumnAndRow(11, 5, "Área del proyecto");
            $hoja->setCellValueByColumnAndRow(12, 5, "Trayectoria de la entidad participante");
            $hoja->setCellValueByColumnAndRow(13, 5, "Problema o necesidad");
            $hoja->setCellValueByColumnAndRow(14, 5, "¿Cómo se diagnosticó el problema o necesidad?");
            $hoja->setCellValueByColumnAndRow(15, 5, "Justificación");
            $hoja->setCellValueByColumnAndRow(16, 5, "Antecedentes generales del proyecto");
            $hoja->setCellValueByColumnAndRow(17, 5, "Metodología");
            $hoja->setCellValueByColumnAndRow(18, 5, "Impacto esperado");
            $hoja->setCellValueByColumnAndRow(19, 5, "Mecanismos de evaluación cualitativa: objetivos e impactos");
            $hoja->setCellValueByColumnAndRow(20, 5, "Mecanismos de evaluación cuantitativa: cobertura poblacional y territorial");
            $hoja->setCellValueByColumnAndRow(21, 5, "Medio por el cual se enteró de esta convocatoria");
            $hoja->setCellValueByColumnAndRow(22, 5, "¿En la ejecución de la propuesta o proyecto participarán menores de edad?");
            $hoja->setCellValueByColumnAndRow(23, 5, "Proyección y reconocimiento nacional o internacional");
            $hoja->setCellValueByColumnAndRow(24, 5, "Impacto que ha tenido el proyecto");
            $hoja->setCellValueByColumnAndRow(25, 5, "Alcance territorial del proyecto");
            $hoja->setCellValueByColumnAndRow(26, 5, "Tipo identificación");
            $hoja->setCellValueByColumnAndRow(27, 5, "Número identificación");
            $hoja->setCellValueByColumnAndRow(28, 5, "Participante");
            $hoja->setCellValueByColumnAndRow(29, 5, "Usuario registrado");
            $hoja->setCellValueByColumnAndRow(30, 5, "Celular");
            $hoja->setCellValueByColumnAndRow(31, 5, "Teléfono");
            $hoja->setCellValueByColumnAndRow(32, 5, "Correo electrónico");


            $utf8_ansi2 = array(
                "\u00c0" => "À",
                "\u00c1" => "Á",
                "\u00c2" => "Â",
                "\u00c3" => "Ã",
                "\u00c4" => "Ä",
                "\u00c5" => "Å",
                "\u00c6" => "Æ",
                "\u00c7" => "Ç",
                "\u00c8" => "È",
                "\u00c9" => "É",
                "\u00ca" => "Ê",
                "\u00cb" => "Ë",
                "\u00cc" => "Ì",
                "\u00cd" => "Í",
                "\u00ce" => "Î",
                "\u00cf" => "Ï",
                "\u00d1" => "Ñ",
                "\u00d2" => "Ò",
                "\u00d3" => "Ó",
                "\u00d4" => "Ô",
                "\u00d5" => "Õ",
                "\u00d6" => "Ö",
                "\u00d8" => "Ø",
                "\u00d9" => "Ù",
                "\u00da" => "Ú",
                "\u00db" => "Û",
                "\u00dc" => "Ü",
                "\u00dd" => "Ý",
                "\u00df" => "ß",
                "\u00e0" => "à",
                "\u00e1" => "á",
                "\u00e2" => "â",
                "\u00e3" => "ã",
                "\u00e4" => "ä",
                "\u00e5" => "å",
                "\u00e6" => "æ",
                "\u00e7" => "ç",
                "\u00e8" => "è",
                "\u00e9" => "é",
                "\u00ea" => "ê",
                "\u00eb" => "ë",
                "\u00ec" => "ì",
                "\u00ed" => "í",
                "\u00ee" => "î",
                "\u00ef" => "ï",
                "\u00f0" => "ð",
                "\u00f1" => "ñ",
                "\u00f2" => "ò",
                "\u00f3" => "ó",
                "\u00f4" => "ô",
                "\u00f5" => "õ",
                "\u00f6" => "ö",
                "\u00f8" => "ø",
                "\u00f9" => "ù",
                "\u00fa" => "ú",
                "\u00fb" => "û",
                "\u00fc" => "ü",
                "\u00fd" => "ý",
                "\u00ff" => "ÿ");

            //Registros de la base de datos
            $fila = 6;
            foreach ($convocatorias as $convocatoria) {
                $hoja->setCellValueByColumnAndRow(1, $fila, $convocatoria->anio);
                $hoja->setCellValueByColumnAndRow(2, $fila, $convocatoria->convocatoria);
                $hoja->setCellValueByColumnAndRow(3, $fila, $convocatoria->categoria);
                $hoja->setCellValueByColumnAndRow(4, $fila, $convocatoria->estado);
                $hoja->setCellValueByColumnAndRow(5, $fila, $convocatoria->nombre_propuesta);
                $hoja->setCellValueByColumnAndRow(6, $fila, $convocatoria->codigo);
                $primera_vez_pdac = "No";
                if ($convocatoria->primera_vez_pdac) {
                    $primera_vez_pdac = "Sí";
                }
                $alianza_sectorial = "No";
                if ($convocatoria->alianza_sectorial) {
                    $alianza_sectorial = "Sí";
                }
                $hoja->setCellValueByColumnAndRow(7, $fila, $primera_vez_pdac);
                $hoja->setCellValueByColumnAndRow(8, $fila, $alianza_sectorial);
                
                //Limpieza de variables
                $relacion_plan=str_replace('","', " , ", $convocatoria->relacion_plan);
                $relacion_plan=str_replace('["', "", $relacion_plan);
                $relacion_plan=str_replace('"]', "", $relacion_plan); 
                
                $relacion_plan = strtr($relacion_plan, $utf8_ansi2);
                
                $linea_estrategica=str_replace('","', " , ", $convocatoria->linea_estrategica );
                $linea_estrategica=str_replace('["', "", $linea_estrategica);
                $linea_estrategica=str_replace('"]', "", $linea_estrategica);

                $linea_estrategica = strtr($linea_estrategica, $utf8_ansi2);
                
                $porque_medio=str_replace('","', " , ", $convocatoria->porque_medio );
                $porque_medio=str_replace('["', "", $porque_medio);
                $porque_medio=str_replace('"]', "", $porque_medio);                        

                $porque_medio = strtr($porque_medio, $utf8_ansi2);
                
                $area=str_replace('","', " , ", $convocatoria->area );
                $area=str_replace('["', "", $area);
                $area=str_replace('"]', "", $area);    

                $area = strtr($area, $utf8_ansi2);
                
                $hoja->setCellValueByColumnAndRow(9, $fila, $relacion_plan);
                $hoja->setCellValueByColumnAndRow(10, $fila, $linea_estrategica);
                $hoja->setCellValueByColumnAndRow(11, $fila, $area);
                $hoja->setCellValueByColumnAndRow(12, $fila, $convocatoria->trayectoria_entidad);
                $hoja->setCellValueByColumnAndRow(13, $fila, $convocatoria->problema_necesidad);
                $hoja->setCellValueByColumnAndRow(14, $fila, $convocatoria->diagnostico_problema);
                $hoja->setCellValueByColumnAndRow(15, $fila, $convocatoria->justificacion);
                $hoja->setCellValueByColumnAndRow(16, $fila, $convocatoria->atencedente);
                $hoja->setCellValueByColumnAndRow(17, $fila, $convocatoria->metodologia);
                $hoja->setCellValueByColumnAndRow(18, $fila, $convocatoria->impacto);
                $hoja->setCellValueByColumnAndRow(19, $fila, $convocatoria->mecanismos_cualitativa);
                $hoja->setCellValueByColumnAndRow(20, $fila, $convocatoria->mecanismos_cuantitativa);
                $hoja->setCellValueByColumnAndRow(21, $fila, $porque_medio);
                
                
                $ejecucion_menores_edad = "No";
                if ($convocatoria->ejecucion_menores_edad) {
                    $ejecucion_menores_edad = "Sí";
                }
                $hoja->setCellValueByColumnAndRow(22, $fila, $ejecucion_menores_edad);
                
                $hoja->setCellValueByColumnAndRow(23, $fila, $convocatoria->proyeccion_reconocimiento);
                $hoja->setCellValueByColumnAndRow(24, $fila, $convocatoria->impacto_proyecto);
                
                $alcance_territorial=str_replace('","', " , ", $convocatoria->alcance_territorial );
                $alcance_territorial=str_replace('["', "", $alcance_territorial);
                $alcance_territorial=str_replace('"]', "", $alcance_territorial); 
                
                $alcance_territorial = strtr($alcance_territorial, $utf8_ansi2);
                
                
                $hoja->setCellValueByColumnAndRow(25, $fila, $alcance_territorial);
                $hoja->setCellValueByColumnAndRow(26, $fila, $convocatoria->tipo_identificacion);
                $hoja->setCellValueByColumnAndRow(27, $fila, $convocatoria->numero_documento);
                $hoja->setCellValueByColumnAndRow(28, $fila, $convocatoria->participante);
                $hoja->setCellValueByColumnAndRow(29, $fila, $convocatoria->usuario_registro);
                $hoja->setCellValueByColumnAndRow(30, $fila, $convocatoria->numero_celular);
                $hoja->setCellValueByColumnAndRow(31, $fila, $convocatoria->numero_telefono);
                $hoja->setCellValueByColumnAndRow(32, $fila, $convocatoria->correo_electronico);
                $fila++;
            }

            //Genero la junta directiva
            $sql_convocatorias = "                           	
                            select 
                                pro.codigo,
                                par.rol,
                                par.representante,
                                td.nombre as tipo_identificacion,
                                par.numero_documento,
                                par.primer_nombre,
                                par.segundo_nombre,
                                par.primer_apellido,
                                par.segundo_apellido,
                                par.tiene_rut,
                                cii.nombre as ciiu,
                                par.tiene_matricula,
                                sex.nombre as sexo,
                                orisex.nombre as orientacion_sexual,
                                idege.nombre as identidad_genero,
                                gret.nombre as grupo_etnico,
                                tide.nombre as discapacidad,
                                par.regimen_salud,
                                par.es_victima,
                                par.fecha_nacimiento,
                                ciun.nombre as ciudad_nacimiento,
                                ciur.nombre as ciudad_residencia,
                                locr.nombre as localidad_residencia,
                                bar.nombre as barrio_residencia,
                                par.direccion_residencia,
                                par.direccion_correspondencia,
                                par.estrato,
                                par.area,
                                par.numero_telefono,
                                par.numero_celular,
                                par.correo_electronico,
                                par.tiene_redes,
                                par.instagram,
                                par.twitter,
                                par.facebook,
                                par.tik_tok,
                                par.linked_in,
                                par.tiene_paginas,
                                par.pagina_web,
                                par.you_tube,
                                par.vimeo,
                                par.blog,
                                par.podcast,
                                par.tipo
                            from Participantes as par
                                inner join Propuestas as pro on pro.participante=par.participante_padre
                                inner JOIN Tiposdocumentos AS td ON td.id=par.tipo_documento
                                LEFT JOIN Ciius AS cii ON cii.id=par.ciiu
                                LEFT JOIN Sexos AS sex ON sex.id=par.sexo
                                LEFT JOIN Orientacionessexuales AS orisex ON orisex.id=par.orientacion_sexual
                                LEFT JOIN Identidadesgeneros AS idege ON idege.id=par.identidad_genero
                                LEFT JOIN Gruposetnicos AS gret ON gret.id=par.grupo_etnico
                                LEFT JOIN Tiposdiscapacidades AS tide ON tide.id=par.discapacidad
                                LEFT JOIN Ciudades AS ciun ON ciun.id=par.ciudad_nacimiento
                                LEFT JOIN Ciudades AS ciur ON ciur.id=par.ciudad_residencia
                                LEFT JOIN Localidades AS locr ON locr.id=par.localidad_residencia
                                LEFT JOIN Barrios AS bar ON bar.id=par.barrio_residencia
                            where 
                                pro.convocatoria=" . $request->getPut('convocatoria') . " AND pro.estado not in (7,20) AND par.active = TRUE
                            ORDER BY pro.codigo
                            ";

            $juntas = $app->modelsManager->executeQuery($sql_convocatorias);

            $hoja2 = $documento->createSheet();
            $hoja2->setTitle("Junta directiva");

            $hoja3 = $documento->createSheet();
            $hoja3->setTitle("Equipo de trabajo");

            //Cabezote de la tabla
            $hoja2->setCellValueByColumnAndRow(1, 1, "Código propuesta");
            $hoja2->setCellValueByColumnAndRow(2, 1, "Rol que desempeña o ejecuta en la propuesta");
            $hoja2->setCellValueByColumnAndRow(3, 1, "¿Representante?");
            $hoja2->setCellValueByColumnAndRow(4, 1, "Tipo de documento de identificación");
            $hoja2->setCellValueByColumnAndRow(5, 1, "Número de documento de identificación");
            $hoja2->setCellValueByColumnAndRow(6, 1, "Primer nombre");
            $hoja2->setCellValueByColumnAndRow(7, 1, "Segundo nombre");
            $hoja2->setCellValueByColumnAndRow(8, 1, "Primer apellido");
            $hoja2->setCellValueByColumnAndRow(9, 1, "Segundo apellido");
            $hoja2->setCellValueByColumnAndRow(10, 1, "¿Tiene RUT?");
            $hoja2->setCellValueByColumnAndRow(11, 1, "¿cuál es el código CIIU de su actividad principal?");
            $hoja2->setCellValueByColumnAndRow(12, 1, "¿Cuenta usted con matrícula mercantil?");
            $hoja2->setCellValueByColumnAndRow(13, 1, "Sexo");
            $hoja2->setCellValueByColumnAndRow(14, 1, "Orientación Sexual");
            $hoja2->setCellValueByColumnAndRow(15, 1, "Identidad de género");
            $hoja2->setCellValueByColumnAndRow(16, 1, "Grupo étnico");
            $hoja2->setCellValueByColumnAndRow(17, 1, "¿Tiene usted algún tipo de discapacidad?");
            $hoja2->setCellValueByColumnAndRow(18, 1, "¿A qué régimen de salud pertenece?");
            $hoja2->setCellValueByColumnAndRow(19, 1, "¿Es usted víctima del conflicto armado?");
            $hoja2->setCellValueByColumnAndRow(20, 1, "Fecha de nacimiento");
            $hoja2->setCellValueByColumnAndRow(21, 1, "Ciudad de nacimiento");
            $hoja2->setCellValueByColumnAndRow(22, 1, "Ciudad de residencia");
            $hoja2->setCellValueByColumnAndRow(23, 1, "Localidad de residencia");
            $hoja2->setCellValueByColumnAndRow(24, 1, "Barrio de residencia");
            $hoja2->setCellValueByColumnAndRow(25, 1, "Dirección de residencia");
            $hoja2->setCellValueByColumnAndRow(26, 1, "Dirección correspondencia");
            $hoja2->setCellValueByColumnAndRow(27, 1, "Estrato");
            $hoja2->setCellValueByColumnAndRow(28, 1, "Área");
            $hoja2->setCellValueByColumnAndRow(29, 1, "Teléfono fijo");
            $hoja2->setCellValueByColumnAndRow(30, 1, "Número de celular personal");
            $hoja2->setCellValueByColumnAndRow(31, 1, "Correo electrónico");
            $hoja2->setCellValueByColumnAndRow(32, 1, "¿Tiene redes sociales personales?");
            $hoja2->setCellValueByColumnAndRow(33, 1, "Instagram");
            $hoja2->setCellValueByColumnAndRow(34, 1, "Twitter");
            $hoja2->setCellValueByColumnAndRow(35, 1, "Facebook");
            $hoja2->setCellValueByColumnAndRow(36, 1, "Tik ToK");
            $hoja2->setCellValueByColumnAndRow(37, 1, "LinkedIn");
            $hoja2->setCellValueByColumnAndRow(38, 1, "¿Cuenta con espacios de circulación de contenidos en línea?");
            $hoja2->setCellValueByColumnAndRow(39, 1, "Página web");
            $hoja2->setCellValueByColumnAndRow(40, 1, "YouTube");
            $hoja2->setCellValueByColumnAndRow(41, 1, "Vimeo");
            $hoja2->setCellValueByColumnAndRow(42, 1, "Blog");
            $hoja2->setCellValueByColumnAndRow(43, 1, "Podcast");

            //Cabezote de la tabla
            $hoja3->setCellValueByColumnAndRow(1, 1, "Código propuesta");
            $hoja3->setCellValueByColumnAndRow(2, 1, "Rol que desempeña o ejecuta en la propuesta");
            $hoja3->setCellValueByColumnAndRow(3, 1, "¿Representante?");
            $hoja3->setCellValueByColumnAndRow(4, 1, "Tipo de documento de identificación");
            $hoja3->setCellValueByColumnAndRow(5, 1, "Número de documento de identificación");
            $hoja3->setCellValueByColumnAndRow(6, 1, "Primer nombre");
            $hoja3->setCellValueByColumnAndRow(7, 1, "Segundo nombre");
            $hoja3->setCellValueByColumnAndRow(8, 1, "Primer apellido");
            $hoja3->setCellValueByColumnAndRow(9, 1, "Segundo apellido");
            $hoja3->setCellValueByColumnAndRow(10, 1, "¿Tiene RUT?");
            $hoja3->setCellValueByColumnAndRow(11, 1, "¿cuál es el código CIIU de su actividad principal?");
            $hoja3->setCellValueByColumnAndRow(12, 1, "¿Cuenta usted con matrícula mercantil?");
            $hoja3->setCellValueByColumnAndRow(13, 1, "Sexo");
            $hoja3->setCellValueByColumnAndRow(14, 1, "Orientación Sexual");
            $hoja3->setCellValueByColumnAndRow(15, 1, "Identidad de género");
            $hoja3->setCellValueByColumnAndRow(16, 1, "Grupo étnico");
            $hoja3->setCellValueByColumnAndRow(17, 1, "¿Tiene usted algún tipo de discapacidad?");
            $hoja3->setCellValueByColumnAndRow(18, 1, "¿A qué régimen de salud pertenece?");
            $hoja3->setCellValueByColumnAndRow(19, 1, "¿Es usted víctima del conflicto armado?");
            $hoja3->setCellValueByColumnAndRow(20, 1, "Fecha de nacimiento");
            $hoja3->setCellValueByColumnAndRow(21, 1, "Ciudad de nacimiento");
            $hoja3->setCellValueByColumnAndRow(22, 1, "Ciudad de residencia");
            $hoja3->setCellValueByColumnAndRow(23, 1, "Localidad de residencia");
            $hoja3->setCellValueByColumnAndRow(24, 1, "Barrio de residencia");
            $hoja3->setCellValueByColumnAndRow(25, 1, "Dirección de residencia");
            $hoja3->setCellValueByColumnAndRow(26, 1, "Dirección correspondencia");
            $hoja3->setCellValueByColumnAndRow(27, 1, "Estrato");
            $hoja3->setCellValueByColumnAndRow(28, 1, "Área");
            $hoja3->setCellValueByColumnAndRow(29, 1, "Teléfono fijo");
            $hoja3->setCellValueByColumnAndRow(30, 1, "Número de celular personal");
            $hoja3->setCellValueByColumnAndRow(31, 1, "Correo electrónico");
            $hoja3->setCellValueByColumnAndRow(32, 1, "¿Tiene redes sociales personales?");
            $hoja3->setCellValueByColumnAndRow(33, 1, "Instagram");
            $hoja3->setCellValueByColumnAndRow(34, 1, "Twitter");
            $hoja3->setCellValueByColumnAndRow(35, 1, "Facebook");
            $hoja3->setCellValueByColumnAndRow(36, 1, "Tik ToK");
            $hoja3->setCellValueByColumnAndRow(37, 1, "LinkedIn");
            $hoja3->setCellValueByColumnAndRow(38, 1, "¿Cuenta con espacios de circulación de contenidos en línea?");
            $hoja3->setCellValueByColumnAndRow(39, 1, "Página web");
            $hoja3->setCellValueByColumnAndRow(40, 1, "YouTube");
            $hoja3->setCellValueByColumnAndRow(41, 1, "Vimeo");
            $hoja3->setCellValueByColumnAndRow(42, 1, "Blog");
            $hoja3->setCellValueByColumnAndRow(43, 1, "Podcast");

            //Registros de la base de datos
            $filaj = 2;
            $filae = 2;
            foreach ($juntas as $junta) {
                if ($junta->tipo == 'Junta') {
                    $hoja2->setCellValueByColumnAndRow(1, $filaj, $junta->codigo);
                    $hoja2->setCellValueByColumnAndRow(2, $filaj, $junta->rol);
                    $representante = "No";
                    if ($convocatoria->representante) {
                        $representante = "Sí";
                    }
                    $hoja2->setCellValueByColumnAndRow(3, $filaj, $representante);
                    $hoja2->setCellValueByColumnAndRow(4, $filaj, $junta->tipo_identificacion);
                    $hoja2->setCellValueByColumnAndRow(5, $filaj, $junta->numero_documento);
                    $hoja2->setCellValueByColumnAndRow(6, $filaj, $junta->primer_nombre);
                    $hoja2->setCellValueByColumnAndRow(7, $filaj, $junta->segundo_nombre);
                    $hoja2->setCellValueByColumnAndRow(8, $filaj, $junta->primer_apellido);
                    $hoja2->setCellValueByColumnAndRow(9, $filaj, $junta->segundo_apellido);
                    $hoja2->setCellValueByColumnAndRow(10, $filaj, $junta->tiene_rut);
                    $hoja2->setCellValueByColumnAndRow(11, $filaj, $junta->ciiu);
                    $hoja2->setCellValueByColumnAndRow(12, $filaj, $junta->tiene_matricula);
                    $hoja2->setCellValueByColumnAndRow(13, $filaj, $junta->sexo);
                    $hoja2->setCellValueByColumnAndRow(14, $filaj, $junta->orientacion_sexual);
                    $hoja2->setCellValueByColumnAndRow(15, $filaj, $junta->identidad_genero);
                    $hoja2->setCellValueByColumnAndRow(16, $filaj, $junta->grupo_etnico);
                    $hoja2->setCellValueByColumnAndRow(17, $filaj, $junta->discapacidad);
                    $hoja2->setCellValueByColumnAndRow(18, $filaj, $junta->regimen_salud);
                    $hoja2->setCellValueByColumnAndRow(19, $filaj, $junta->es_victima);
                    $hoja2->setCellValueByColumnAndRow(20, $filaj, $junta->fecha_nacimiento);
                    $hoja2->setCellValueByColumnAndRow(21, $filaj, $junta->ciudad_nacimiento);
                    $hoja2->setCellValueByColumnAndRow(22, $filaj, $junta->ciudad_residencia);
                    $hoja2->setCellValueByColumnAndRow(23, $filaj, $junta->localidad_residencia);
                    $hoja2->setCellValueByColumnAndRow(24, $filaj, $junta->barrio_residencia);
                    $hoja2->setCellValueByColumnAndRow(25, $filaj, $junta->direccion_residencia);
                    $hoja2->setCellValueByColumnAndRow(26, $filaj, $junta->direccion_correspondencia);
                    $hoja2->setCellValueByColumnAndRow(27, $filaj, $junta->estrato);
                    $hoja2->setCellValueByColumnAndRow(28, $filaj, $junta->area);
                    $hoja2->setCellValueByColumnAndRow(29, $filaj, $junta->numero_telefono);
                    $hoja2->setCellValueByColumnAndRow(30, $filaj, $junta->numero_celular);
                    $hoja2->setCellValueByColumnAndRow(31, $filaj, $junta->correo_electronico);
                    $hoja2->setCellValueByColumnAndRow(32, $filaj, $junta->tiene_redes);
                    $hoja2->setCellValueByColumnAndRow(33, $filaj, $junta->instagram);
                    $hoja2->setCellValueByColumnAndRow(34, $filaj, $junta->twitter);
                    $hoja2->setCellValueByColumnAndRow(35, $filaj, $junta->facebook);
                    $hoja2->setCellValueByColumnAndRow(36, $filaj, $junta->tik_tok);
                    $hoja2->setCellValueByColumnAndRow(37, $filaj, $junta->linked_in);
                    $hoja2->setCellValueByColumnAndRow(38, $filaj, $junta->tiene_paginas);
                    $hoja2->setCellValueByColumnAndRow(39, $filaj, $junta->pagina_web);
                    $hoja2->setCellValueByColumnAndRow(40, $filaj, $junta->you_tube);
                    $hoja2->setCellValueByColumnAndRow(41, $filaj, $junta->vimeo);
                    $hoja2->setCellValueByColumnAndRow(42, $filaj, $junta->blog);
                    $hoja2->setCellValueByColumnAndRow(43, $filaj, $junta->podcast);
                    $filaj++;
                } else {
                    $hoja3->setCellValueByColumnAndRow(1, $filae, $junta->codigo);
                    $hoja3->setCellValueByColumnAndRow(2, $filae, $junta->rol);
                    $representante = "No";
                    if ($convocatoria->representante) {
                        $representante = "Sí";
                    }
                    $hoja3->setCellValueByColumnAndRow(3, $filae, $representante);
                    $hoja3->setCellValueByColumnAndRow(4, $filae, $junta->tipo_identificacion);
                    $hoja3->setCellValueByColumnAndRow(5, $filae, $junta->numero_documento);
                    $hoja3->setCellValueByColumnAndRow(6, $filae, $junta->primer_nombre);
                    $hoja3->setCellValueByColumnAndRow(7, $filae, $junta->segundo_nombre);
                    $hoja3->setCellValueByColumnAndRow(8, $filae, $junta->primer_apellido);
                    $hoja3->setCellValueByColumnAndRow(9, $filae, $junta->segundo_apellido);
                    $hoja3->setCellValueByColumnAndRow(10, $filae, $junta->tiene_rut);
                    $hoja3->setCellValueByColumnAndRow(11, $filae, $junta->ciiu);
                    $hoja3->setCellValueByColumnAndRow(12, $filae, $junta->tiene_matricula);
                    $hoja3->setCellValueByColumnAndRow(13, $filae, $junta->sexo);
                    $hoja3->setCellValueByColumnAndRow(14, $filae, $junta->orientacion_sexual);
                    $hoja3->setCellValueByColumnAndRow(15, $filae, $junta->identidad_genero);
                    $hoja3->setCellValueByColumnAndRow(16, $filae, $junta->grupo_etnico);
                    $hoja3->setCellValueByColumnAndRow(17, $filae, $junta->discapacidad);
                    $hoja3->setCellValueByColumnAndRow(18, $filae, $junta->regimen_salud);
                    $hoja3->setCellValueByColumnAndRow(19, $filae, $junta->es_victima);
                    $hoja3->setCellValueByColumnAndRow(20, $filae, $junta->fecha_nacimiento);
                    $hoja3->setCellValueByColumnAndRow(21, $filae, $junta->ciudad_nacimiento);
                    $hoja3->setCellValueByColumnAndRow(22, $filae, $junta->ciudad_residencia);
                    $hoja3->setCellValueByColumnAndRow(23, $filae, $junta->localidad_residencia);
                    $hoja3->setCellValueByColumnAndRow(24, $filae, $junta->barrio_residencia);
                    $hoja3->setCellValueByColumnAndRow(25, $filae, $junta->direccion_residencia);
                    $hoja3->setCellValueByColumnAndRow(26, $filae, $junta->direccion_correspondencia);
                    $hoja3->setCellValueByColumnAndRow(27, $filae, $junta->estrato);
                    $hoja3->setCellValueByColumnAndRow(28, $filae, $junta->area);
                    $hoja3->setCellValueByColumnAndRow(29, $filae, $junta->numero_telefono);
                    $hoja3->setCellValueByColumnAndRow(30, $filae, $junta->numero_celular);
                    $hoja3->setCellValueByColumnAndRow(31, $filae, $junta->correo_electronico);
                    $hoja3->setCellValueByColumnAndRow(32, $filae, $junta->tiene_redes);
                    $hoja3->setCellValueByColumnAndRow(33, $filae, $junta->instagram);
                    $hoja3->setCellValueByColumnAndRow(34, $filae, $junta->twitter);
                    $hoja3->setCellValueByColumnAndRow(35, $filae, $junta->facebook);
                    $hoja3->setCellValueByColumnAndRow(36, $filae, $junta->tik_tok);
                    $hoja3->setCellValueByColumnAndRow(37, $filae, $junta->linked_in);
                    $hoja3->setCellValueByColumnAndRow(38, $filae, $junta->tiene_paginas);
                    $hoja3->setCellValueByColumnAndRow(39, $filae, $junta->pagina_web);
                    $hoja3->setCellValueByColumnAndRow(40, $filae, $junta->you_tube);
                    $hoja3->setCellValueByColumnAndRow(41, $filae, $junta->vimeo);
                    $hoja3->setCellValueByColumnAndRow(42, $filae, $junta->blog);
                    $hoja3->setCellValueByColumnAndRow(43, $filae, $junta->podcast);
                    $filae++;
                }
            }

            $hoja4 = $documento->createSheet();
            $hoja4->setTitle("Objetivos, metas y actividades");


            //Cabezote de la tabla
            $hoja4->setCellValueByColumnAndRow(1, 1, "Código propuesta");
            $hoja4->setCellValueByColumnAndRow(2, 1, "Objetivo general");
            $hoja4->setCellValueByColumnAndRow(3, 1, "Objetivo específico");
            $hoja4->setCellValueByColumnAndRow(4, 1, "Meta");
            $hoja4->setCellValueByColumnAndRow(5, 1, "Actividad");
            $hoja4->setCellValueByColumnAndRow(6, 1, "Semana de ejecución");
            $hoja4->setCellValueByColumnAndRow(7, 1, "Insumo");
            $hoja4->setCellValueByColumnAndRow(8, 1, "Cantidad");
            $hoja4->setCellValueByColumnAndRow(9, 1, "Unidad de medida");
            $hoja4->setCellValueByColumnAndRow(10, 1, "Valor unitario");
            $hoja4->setCellValueByColumnAndRow(11, 1, "Valor Total");
            $hoja4->setCellValueByColumnAndRow(12, 1, "Aporte solicitado concertación");
            $hoja4->setCellValueByColumnAndRow(13, 1, "Aporte cofinanciado por terceros");
            $hoja4->setCellValueByColumnAndRow(14, 1, "Aporte recursos propios");

            //Genero la objetivos
            $sql_convocatorias = "                           	
                            select 
                                    pro.codigo,
                                    pro.objetivo_general,
                                    po.objetivo,
                                    po.meta,
                                    pa.actividad,
                                    pc.fecha,
                                    pp.insumo,
                                    pp.cantidad,
                                    pp.unidadmedida,
                                    pp.valorunitario,
                                    pp.valortotal,
                                    pp.aportesolicitado,
                                    pp.aportecofinanciado,
                                    pp.aportepropio
                            from Propuestasobjetivos as po 
                                inner join Propuestas as pro on pro.id=po.propuesta
                                inner join Propuestasactividades as pa on pa.propuestaobjetivo=po.id
                                inner join Propuestascronogramas as pc on pc.propuestaactividad=pa.id
                                inner join Propuestaspresupuestos as pp on pp.propuestaactividad=pa.id
                            WHERE pro.convocatoria=" . $request->getPut('convocatoria') . " AND pro.estado not in (7,20) and po.active=true and pa.active=true and pc.active=true and pp.active=true
                            ORDER BY pro.codigo
                            ";

            $objetivos = $app->modelsManager->executeQuery($sql_convocatorias);

            //Registros de la base de datos
            $fila = 2;
            foreach ($objetivos as $objetivo) {
                $hoja4->setCellValueByColumnAndRow(1, $fila, $objetivo->codigo);
                $hoja4->setCellValueByColumnAndRow(2, $fila, $objetivo->objetivo_general);
                $hoja4->setCellValueByColumnAndRow(3, $fila, $objetivo->objetivo);
                $hoja4->setCellValueByColumnAndRow(4, $fila, $objetivo->meta);
                $hoja4->setCellValueByColumnAndRow(5, $fila, $objetivo->actividad);
                $hoja4->setCellValueByColumnAndRow(6, $fila, $objetivo->fecha);
                $hoja4->setCellValueByColumnAndRow(7, $fila, $objetivo->insumo);
                $hoja4->setCellValueByColumnAndRow(8, $fila, $objetivo->cantidad);
                $hoja4->setCellValueByColumnAndRow(9, $fila, $objetivo->unidadmedida);
                $hoja4->setCellValueByColumnAndRow(10, $fila, $objetivo->valorunitario);
                $hoja4->setCellValueByColumnAndRow(11, $fila, $objetivo->valortotal);
                $hoja4->setCellValueByColumnAndRow(12, $fila, $objetivo->aportesolicitado);
                $hoja4->setCellValueByColumnAndRow(13, $fila, $objetivo->aportecofinanciado);
                $hoja4->setCellValueByColumnAndRow(14, $fila, $objetivo->aportepropio);
                $fila++;
            }

            $hoja5 = $documento->createSheet();
            $hoja5->setTitle("Territorios y población");


            //Cabezote de la tabla
            $hoja5->setCellValueByColumnAndRow(1, 1, "Código propuesta");
            $hoja5->setCellValueByColumnAndRow(2, 1, "Localidad");
            $hoja5->setCellValueByColumnAndRow(3, 1, "Localidades");
            $hoja5->setCellValueByColumnAndRow(4, 1, "Describa brevemente la población objetivo del proyecto");
            $hoja5->setCellValueByColumnAndRow(5, 1, "¿Cómo se concertó el proyecto con la comunidad objetivo?");
            $hoja5->setCellValueByColumnAndRow(6, 1, "Estimado total de beneficiarios o participantes");
            $hoja5->setCellValueByColumnAndRow(7, 1, "¿Cómo se estableció esta cifra?");
            $hoja5->setCellValueByColumnAndRow(8, 1, "Población");
            $hoja5->setCellValueByColumnAndRow(9, 1, "Valor");

            //Genero la territorios
            $sql_convocatorias = "                           	
                            select 
                                    pro.codigo,
                                    lo.nombre as localidad,
                                    pro.localidades,
                                    pro.poblacion_objetivo,
                                    pro.comunidad_objetivo,
                                    pro.total_beneficiario,
                                    pro.establecio_cifra,
                                    pt.variable,
                                    pt.valor
                            from Propuestasterritorios as pt 
                                inner join Propuestas as pro on pro.id=pt.propuesta
                                left join Localidades as lo on lo.id=pro.localidad
                            WHERE pro.convocatoria=" . $request->getPut('convocatoria') . " AND pro.estado not in (7,20)
                            ORDER BY pro.codigo
                            ";

            $territorios = $app->modelsManager->executeQuery($sql_convocatorias);

            //Registros de la base de datos
            $fila = 2;
            foreach ($territorios as $territorio) {
                $hoja5->setCellValueByColumnAndRow(1, $fila, $territorio->codigo);
                $hoja5->setCellValueByColumnAndRow(2, $fila, $territorio->localidad);
                $hoja5->setCellValueByColumnAndRow(3, $fila, $territorio->localidades);
                $hoja5->setCellValueByColumnAndRow(4, $fila, $territorio->poblacion_objetivo);
                $hoja5->setCellValueByColumnAndRow(5, $fila, $territorio->comunidad_objetivo);
                $hoja5->setCellValueByColumnAndRow(6, $fila, $territorio->total_beneficiario);
                $hoja5->setCellValueByColumnAndRow(7, $fila, $territorio->establecio_cifra);
                $hoja5->setCellValueByColumnAndRow(8, $fila, $territorio->variable);
                $hoja5->setCellValueByColumnAndRow(9, $fila, $territorio->valor);
                $fila++;
            }


            $nombreDelDocumento = "listado_entidades_convocatorias_listado_no_inscritas_" . $entidad->id . "_" . $anio . ".xlsx";

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
            $logger->error('"token":"{token}","user":"{user}","message":"Token caduco en el metodo reporte_listado_entidades_convocatorias_listado_jurados_xls al generar el reporte listado de la propuesta (' . $request->getPut('id') . ')', ['user' => "", 'token' => $request->getPut('token')]);
            $logger->close();
            echo "error_token";
        }
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias           
        $logger->error('"token":"{token}","user":"{user}","message":"Error metodo reporte_listado_entidades_convocatorias_listado_jurados_xls al generar el reporte listado de la propuesta (' . $request->getPut('id') . ')' . $ex->getMessage() . '"', ['user' => "", 'token' => $request->getPut('token')]);
        $logger->close();
        echo "error_metodo";
    }
});

$app->post('/reporte_ganadores', function () use ($app, $config, $logger) {

//Instancio los objetos que se van a manejar
    $request = new Request();
    $tokens = new Tokens();

    try {

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPut('token'));

        //Registro la accion en el log de convocatorias
        $logger->info('"token":"{token}","user":"{user}","message":"Ingresa al metodo reporte_listado_entidades_convocatorias_estado para generar reporte de listado de inscripcion de la propuesta (' . $request->getPut('id') . ')"', ['user' => '', 'token' => $request->getPut('token')]);

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

            //Consulto lo necesario
            $user_current = json_decode($token_actual->user_current, true);


            $where_entidad = "";
            if ($request->getPut('entidad') != "" && $request->getPut('entidad') != "null") {
                $where_entidad = " AND vp.id_entidad=" . $request->getPut('entidad');
            }

            //Genero reporte propuestas por estado
            $sql_convocatorias = "
                SELECT 
                    vp.*
                FROM
                    Viewpropuestas AS vp
                WHERE vp.anio='" . $request->getPut('anio') . "' AND vp.id_estado=34 " . $where_entidad;

            $convocatorias = $app->modelsManager->executeQuery($sql_convocatorias);

            $html_propuestas = "";
            foreach ($convocatorias as $convocatoria) {
                $html_propuestas = $html_propuestas . "<tr>";
                $html_propuestas = $html_propuestas . "<td>" . $convocatoria->anio . "</td>";
                $html_propuestas = $html_propuestas . "<td>" . $convocatoria->nombre_entidad . "</td>";
                $html_propuestas = $html_propuestas . "<td>" . $convocatoria->convocatoria . "</td>";
                $html_propuestas = $html_propuestas . "<td>" . $convocatoria->categoria . "</td>";
                $html_propuestas = $html_propuestas . "<td>" . $convocatoria->propuesta . "</td>";
                $html_propuestas = $html_propuestas . "<td>" . $convocatoria->codigo . "</td>";
                $html_propuestas = $html_propuestas . "<td>" . $convocatoria->estado_propuesta . "</td>";
                $html_propuestas = $html_propuestas . "<td>" . $convocatoria->numero_resolucion . "</td>";
                $html_propuestas = $html_propuestas . "<td>" . $convocatoria->fecha_resolucion . "</td>";
                $html_propuestas = $html_propuestas . "<td>" . $convocatoria->monto_asignado . "</td>";
                $html_propuestas = $html_propuestas . "<td>" . $convocatoria->codigo_presupuestal . "</td>";
                $html_propuestas = $html_propuestas . "<td>" . $convocatoria->codigo_proyecto_inversion . "</td>";
                $html_propuestas = $html_propuestas . "<td>" . $convocatoria->cdp . "</td>";
                $html_propuestas = $html_propuestas . "<td>" . $convocatoria->crp . "</td>";
                $html_propuestas = $html_propuestas . "</tr>";
            }

            $html = '<table border="1" cellpadding="2" cellspacing="2" nobr="true">
                    <tr>
                        <td colspan="14" align="center">Reporte de Ganadores</td>
                    </tr>
                    <tr>
                        <td colspan="14" align="center"> Fecha de corte ' . date("Y-m-d H:i:s") . '</td>
                    </tr>
                    <tr style="background-color:#BDBDBD;color:#OOOOOO;">
                        <td align="center">Año</td>
                        <td align="center">Entidad</td>
                        <td align="center">Convocatoria</td>
                        <td align="center">Categoría</td>                        
                        <td align="center">Propuesta</td>                        
                        <td align="center">Código</td>                        
                        <td align="center">Estado</td>                        
                        <td align="center">Número de resolución</td>                        
                        <td align="center">Fecha de resolución</td>                        
                        <td align="center">Monto asignado</td>                        
                        <td align="center">Código presupuestal</td>                        
                        <td align="center">Código proyecto de inversión</td>                        
                        <td align="center">CDP</td>                        
                        <td align="center">CRP</td>                        
                    </tr> 
                    ' . $html_propuestas . '
                </table>';

            $logger->info('"token":"{token}","user":"{user}","message":"Se genero el reporte de inscripcion de la propuesta (' . $request->getPut('id') . ')', ['user' => $user_current["username"], 'token' => $request->getPut('token')]);
            $logger->close();
            echo $html;
        } else {
            //Registro la accion en el log de convocatorias           
            $logger->error('"token":"{token}","user":"{user}","message":"Token caduco en el metodo reporte_listado_entidades_convocatorias_estado al generar el reporte listado de la propuesta (' . $request->getPut('id') . ')', ['user' => "", 'token' => $request->getPut('token')]);
            $logger->close();
            echo "error_token";
        }
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias           
        $logger->error('"token":"{token}","user":"{user}","message":"Error metodo reporte_listado_entidades_convocatorias_estado al generar el reporte listado de la propuesta (' . $request->getPut('id') . ')' . $ex->getMessage() . '"', ['user' => "", 'token' => $request->getPut('token')]);
        $logger->close();
        echo "error_metodo";
    }
});

$app->post('/reporte_inhabilidades_propuestas', function () use ($app, $config, $logger) {

    //Instancio los objetos que se van a manejar
    $request = new Request();
    $tokens = new Tokens();

    try {

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPut('token'));

        //Registro la accion en el log de convocatorias
        $logger->info('"token":"{token}","user":"{user}","message":"Ingresa al metodo reporte_listado_entidades_convocatorias_estado para generar reporte de listado de inscripcion de la propuesta (' . $request->getPut('id') . ')"', ['user' => '', 'token' => $request->getPut('token')]);

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

            //Consulto lo necesario
            $user_current = json_decode($token_actual->user_current, true);


            $in_codigos = "";

            $html_propuestas = "";

            $codigos = str_replace("'", "", $request->get("codigos"));
            $codigos = str_replace('"', "", $request->get("codigos"));

            $html = '<table border="1" cellpadding="2" cellspacing="2" nobr="true">
                        <tr>
                            <td colspan="11" align="center">Reporte de inhabilidades por propuestas</td>
                        </tr>
                        <tr>
                            <td colspan="11" align="center">Códigos de propuestas: ' . $codigos . '</td>
                        </tr>                    
                        <tr>
                            <td colspan="11" align="center">Fecha de corte ' . date("Y-m-d H:i:s") . '</td>
                        </tr>
                        <tr style="background-color:#BDBDBD;color:#OOOOOO;">
                            <td align="center">Año</td>
                            <td align="center">Entidad</td>
                            <td align="center">Convocatoria - Categoría</td>
                            <td align="center">Estado Convocatoria</td>
                            <td align="center">Código propuestas</td>
                            <td align="center">Número de documento</td>
                            <td align="center">Primer nombre</td>
                            <td align="center">Segundo nombre</td>
                            <td align="center">Primer apellido</td>
                            <td align="center">Segundo apellido</td>                        
                            <td align="center">Inhabilidad o Reporte</td>
                        </tr>                    
                    ';

            if ($request->get("codigos") != "") {

                $array_codigos = explode(",", $codigos);
                foreach ($array_codigos as $clave) {

                    $in_codigos = $in_codigos . ",'" . $clave . "'";
                }

                $in_codigos = trim($in_codigos, ",");

                //CONTRATISTAS
                $sql_convocatorias = "
                    SELECT 
                            vcon.anio,
                            CONCAT(vcon.convocatoria,' ',vcon.categoria) AS convocatoria,
                            vcon.nombre_entidad,
                            p.codigo,
                            par.numero_documento,
                            par.primer_nombre,
                            par.segundo_nombre,
                            par.primer_apellido,
                            par.segundo_apellido,	 
                            concat('Contratista ',ent.nombre) AS inhabilidad,
                            est.id AS id_estado_convocatoria,
                            est.nombre AS estado_convocatoria,
                            p.convocatoria AS id_convocatoria                            
                    FROM 
                            Propuestas AS p
                    INNER JOIN Participantes AS par ON par.id=p.participante OR par.participante_padre=p.participante
                    INNER JOIN Entidadescontratistas AS entcon ON REPLACE(REPLACE(TRIM(entcon.numero_documento),'.',''),' ', '') = REPLACE(REPLACE(TRIM(par.numero_documento),'.',''),' ', '')
                    INNER JOIN Entidades AS ent ON ent.id=entcon.entidad
                    INNER JOIN Viewconvocatorias AS vcon ON vcon.id_categoria=p.convocatoria
                    LEFT JOIN Estados AS est ON vcon.estado=est.id
                    WHERE 
                            p.codigo IN (" . $in_codigos . ") AND entcon.active=TRUE

                    ";

                $convocatorias = $app->modelsManager->executeQuery($sql_convocatorias);

                $array_inhabilidades = array();

                foreach ($convocatorias as $convocatoria) {
                    $array_inhabilidades[] = (array) $convocatoria;
                }

                //GANADORES AÑOS ANTERIORES
                $sql_convocatorias = "
                    SELECT 
                            gaan.anio,
                            CONCAT(gaan.convocatoria,' ',gaan.categoria) AS convocatoria,
                            gaan.entidad AS nombre_entidad,
                            gaan.codigo_propuesta AS codigo,                        
                            par.numero_documento,
                            par.primer_nombre,
                            par.segundo_nombre,
                            par.primer_apellido,
                            par.segundo_apellido,	 
                            CONCAT(gaan.tipo_rol,' Ganadora') AS inhabilidad,
                            '7' AS id_estado_convocatoria,
                            'Adjudicada' AS estado_convocatoria,
                            '' AS id_convocatoria
                    FROM 
                            Propuestas AS p
                    INNER JOIN Participantes AS par ON par.id=p.participante OR par.participante_padre=p.participante
                    INNER JOIN Ganadoresantes2020 AS gaan ON REPLACE(REPLACE(TRIM(gaan.numero_documento),'.',''),' ', '') = REPLACE(REPLACE(TRIM(par.numero_documento),'.',''),' ', '') 
                    WHERE                 
                            p.codigo IN (" . $in_codigos . ")

                    ";

                $convocatorias = $app->modelsManager->executeQuery($sql_convocatorias);

                foreach ($convocatorias as $convocatoria) {
                    $array_inhabilidades[] = (array) $convocatoria;
                }

                //PARTICIPANTES
                $sql_convocatorias = "
                    SELECT 
                            vcon.anio,
                            CONCAT(vcon.convocatoria,' ',vcon.categoria) AS convocatoria,
                            vcon.nombre_entidad,
                            pro.codigo,
                            par.numero_documento,
                            par.primer_nombre,
                            par.segundo_nombre,
                            par.primer_apellido,
                            par.segundo_apellido,
                            concat(par.tipo,' - ',est.nombre) AS inhabilidad,
                            estc.id AS id_estado_convocatoria,
                            estc.nombre AS estado_convocatoria,
                            pro.convocatoria AS id_convocatoria	
                    FROM 
                            Participantes AS par
                    INNER JOIN Propuestas AS pro ON pro.participante=par.id AND pro.estado NOT IN (7,20)
                    INNER JOIN Estados AS est ON est.id=pro.estado
                    INNER JOIN Viewconvocatorias AS vcon ON vcon.id_categoria=pro.convocatoria
                    LEFT JOIN Estados AS estc ON vcon.estado=estc.id
                    WHERE 
                            par.numero_documento 
                            IN (
                                    SELECT	
                                            par.numero_documento	
                                    FROM 
                                            Propuestas AS p
                                    INNER JOIN Participantes AS par ON par.tipo_documento IS NOT NULL AND (par.id=p.participante OR par.participante_padre=p.participante) 
                                    WHERE 
                                            p.codigo IN (" . $in_codigos . ")
                            )
                ";

                $convocatorias = $app->modelsManager->executeQuery($sql_convocatorias);

                foreach ($convocatorias as $convocatoria) {
                    $array_inhabilidades[] = (array) $convocatoria;
                }

                //INTEGRANTES
                $sql_convocatorias = "
                    SELECT
                            vcon.anio,
                            CONCAT(vcon.convocatoria,' ',vcon.categoria) AS convocatoria,
                            vcon.nombre_entidad,
                            pro.codigo,
                            par.numero_documento,
                            par.primer_nombre,
                            par.segundo_nombre,
                            par.primer_apellido,
                            par.segundo_apellido,
                            concat(par.tipo,' - ',est.nombre) AS inhabilidad,
                            estc.id AS id_estado_convocatoria,
                            estc.nombre AS estado_convocatoria,
                            pro.convocatoria AS id_convocatoria
                    FROM 
                            Participantes AS par
                    INNER JOIN Propuestas AS pro ON pro.participante=par.participante_padre AND pro.estado NOT IN (7,20)
                    INNER JOIN Estados AS est ON est.id=pro.estado
                    INNER JOIN Viewconvocatorias AS vcon ON vcon.id_categoria=pro.convocatoria
                    LEFT JOIN Estados AS estc ON vcon.estado=estc.id
                    WHERE 
                            par.numero_documento 
                            IN (
                                    SELECT	
                                            par.numero_documento	
                                    FROM 
                                            Propuestas AS p
                                    INNER JOIN Participantes AS par ON par.tipo_documento IS NOT NULL AND (par.id=p.participante OR par.participante_padre=p.participante) 
                                    WHERE 
                                            p.codigo IN (" . $in_codigos . ")
                            )

                ";

                $convocatorias = $app->modelsManager->executeQuery($sql_convocatorias);

                foreach ($convocatorias as $convocatoria) {
                    $array_inhabilidades[] = (array) $convocatoria;
                }

                //JURADOS SELECCIONADOS
                $sql_convocatorias = "
                    SELECT
                        vcon.anio,
                        CONCAT(vcon.convocatoria,' ',vcon.categoria) AS convocatoria,
                        vcon.nombre_entidad,
                        pro.codigo,
                        par.numero_documento,
                        par.primer_nombre,
                        par.segundo_nombre,
                        par.primer_apellido,
                        par.segundo_apellido,
                        concat('Seleccionado - ',jp.rol) AS inhabilidad
                    FROM Juradospostulados as jp
                        INNER JOIN Evaluadores ev ON jp.id=ev.juradopostulado 
                        INNER JOIN Propuestas AS pro ON jp.propuesta = pro.id
                        INNER JOIN Participantes par on pro.participante = par.id    
                        INNER JOIN Viewconvocatorias AS vcon ON vcon.id_categoria=jp.convocatoria           
                    WHERE 	
                        jp.active=true AND
                        ev.active = true AND	                            
                        REPLACE(REPLACE(TRIM(par.numero_documento),'.',''),' ', '') 
                        IN (
                                    SELECT	
                                            REPLACE(REPLACE(TRIM(par.numero_documento),'.',''),' ', '')
                                    FROM 
                                            Propuestas AS p
                                    INNER JOIN Participantes AS par ON par.tipo_documento IS NOT NULL AND (par.id=p.participante OR par.participante_padre=p.participante) 
                                    WHERE 
                                            p.codigo IN (" . $in_codigos . ")
                            )


                ";

                $convocatorias = $app->modelsManager->executeQuery($sql_convocatorias);

                foreach ($convocatorias as $convocatoria) {
                    $array_inhabilidades[] = (array) $convocatoria;
                }

                //JURADOS EN PROCESO
                $sql_convocatorias = "
                    SELECT
                        vcon.anio,
                        CONCAT(vcon.convocatoria,' ',vcon.categoria) AS convocatoria,
                        vcon.nombre_entidad,
                        pro.codigo,
                        par.numero_documento,
                        par.primer_nombre,
                        par.segundo_nombre,
                        par.primer_apellido,
                        par.segundo_apellido,
                        concat(e.nombre) AS inhabilidad                    
                    FROM Juradospostulados as jp
                        INNER JOIN Propuestas AS pro ON jp.propuesta = pro.id
                        INNER JOIN Participantes AS par ON pro.participante = par.id    
                        INNER JOIN viewconvocatorias AS vcon ON vcon.id_categoria=jp.convocatoria
                        LEFT JOIN Estados AS e ON jp.estado=e.id
                    WHERE 
                        jp.active=TRUE AND  
                        REPLACE(REPLACE(TRIM(par.numero_documento),'.',''),' ', '')
                        IN (
                                    SELECT	
                                            REPLACE(REPLACE(TRIM(par.numero_documento),'.',''),' ', '')
                                    FROM 
                                            Propuestas AS p
                                    INNER JOIN participantes AS par ON par.tipo_documento IS NOT NULL AND (par.id=p.participante OR par.participante_padre=p.participante) 
                                    WHERE 
                                            p.codigo IN (" . $in_codigos . ")
                            )
                ";

                $convocatorias = $app->modelsManager->executeQuery($sql_convocatorias);

                foreach ($convocatorias as $convocatoria) {
                    $array_inhabilidades[] = (array) $convocatoria;
                }

                array_sort_by($array_inhabilidades, 'numero_documento', $order = SORT_ASC);

                foreach ($array_inhabilidades as $convocatoria) {

                    $estado_convocatoria = "";

                    if (isset($convocatoria[id_convocatoria]) AND $convocatoria[id_convocatoria] != "") {
                        $estado_convocatoria = $convocatoria[estado_convocatoria];

                        if ($convocatoria[id_estado_convocatoria] == 5) {
                            $fecha_actual = strtotime(date("Y-m-d H:i:s"), time());
                            $fecha_cierre_real = Convocatoriascronogramas::findFirst("convocatoria=" . $convocatoria[id_convocatoria] . " AND tipo_evento = 12");
                            $fecha_cierre = strtotime($fecha_cierre_real->fecha_fin, time());
                            if ($fecha_actual > $fecha_cierre) {
                                $estado_convocatoria = "Publicada Cerrada";
                            } else {
                                $fecha_apertura_real = Convocatoriascronogramas::findFirst("convocatoria=" . $convocatoria[id_convocatoria] . " AND tipo_evento = 11");
                                $fecha_apertura = strtotime($fecha_apertura_real->fecha_fin, time());
                                if ($fecha_actual > $fecha_apertura) {
                                    $estado_convocatoria = "Publicada Abierta";
                                }
                            }
                        }
                    }

                    $html_propuestas = $html_propuestas . "<tr>";
                    $html_propuestas = $html_propuestas . "<td>" . $convocatoria[anio] . "</td>";
                    $html_propuestas = $html_propuestas . "<td>" . $convocatoria[nombre_entidad] . "</td>";
                    $html_propuestas = $html_propuestas . "<td>" . $convocatoria[convocatoria] . "</td>";
                    $html_propuestas = $html_propuestas . "<td>" . $estado_convocatoria . "</td>";
                    $html_propuestas = $html_propuestas . "<td>" . $convocatoria[codigo] . "</td>";
                    $html_propuestas = $html_propuestas . "<td>" . $convocatoria[numero_documento] . "</td>";
                    $html_propuestas = $html_propuestas . "<td>" . $convocatoria[primer_nombre] . "</td>";
                    $html_propuestas = $html_propuestas . "<td>" . $convocatoria[segundo_nombre] . "</td>";
                    $html_propuestas = $html_propuestas . "<td>" . $convocatoria[primer_apellido] . "</td>";
                    $html_propuestas = $html_propuestas . "<td>" . $convocatoria[segundo_apellido] . "</td>";
                    $html_propuestas = $html_propuestas . "<td>" . $convocatoria[inhabilidad] . "</td>";
                    $html_propuestas = $html_propuestas . "</tr>";
                }

                $html = $html . $html_propuestas;
            }

            $html = $html . $html_propuestas . "</table>";

            $logger->info('"token":"{token}","user":"{user}","message":"Se genero el reporte de inscripcion de la propuesta (' . $request->getPut('id') . ')', ['user' => $user_current["username"], 'token' => $request->getPut('token')]);
            $logger->close();
            echo $html;
        } else {
            //Registro la accion en el log de convocatorias           
            $logger->error('"token":"{token}","user":"{user}","message":"Token caduco en el metodo reporte_listado_entidades_convocatorias_estado al generar el reporte listado de la propuesta (' . $request->getPut('id') . ')', ['user' => "", 'token' => $request->getPut('token')]);
            $logger->close();
            echo "error_token";
        }
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias           
        $logger->error('"token":"{token}","user":"{user}","message":"Error metodo reporte_listado_entidades_convocatorias_estado al generar el reporte listado de la propuesta (' . $request->getPut('id') . ')' . $ex->getMessage() . '"', ['user' => "", 'token' => $request->getPut('token')]);
        $logger->close();
        echo "error_metodo";
    }
});

$app->post('/reporte_ganadores_xls', function () use ($app, $config, $logger) {

    //Instancio los objetos que se van a manejar
    $request = new Request();
    $tokens = new Tokens();

    try {
        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPut('token'));

        //Registro la accion en el log de convocatorias
        $logger->info('"token":"{token}","user":"{user}","message":"Ingresa al metodo reporte_listado_entidades_convocatorias_listado_jurados_xls para generar reporte de listado de inscripcion de la propuesta (' . $request->getPut('id') . ')"', ['user' => '', 'token' => $request->getPut('token')]);

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {


            require_once("../library/phpspreadsheet/autoload.php");

            $anio = $request->getPut('anio');

            $where_entidad = "";
            if ($request->getPut('entidad') != "" && $request->getPut('entidad') != "null") {
                $where_entidad = " AND vwp.id_entidad=" . $request->getPut('entidad');
            }
            //Genero reporte propuestas por estado
            $sql_convocatorias = "
                SELECT 
                        vwp.anio,
                        vwp.entidad,
                        vwp.convocatoria,
                        vwp.id_convocatoria,
                        vwp.codigo,
                        vwp.estado_propuesta,
                        vwp.nombre_propuesta,
                        vwp.tipo_participante,
                        vwp.representante,
                        vwp.tipo_rol,
                        vwp.rol,
                        vwp.numero_documento,
                        vwp.primer_nombre,
                        vwp.segundo_nombre,
                        vwp.primer_apellido,
                        vwp.segundo_apellido,
                        vwp.id_tipo_documento,
                        vwp.tipo_documento,
                        vwp.fecha_nacimiento,
                        vwp.sexo,
                        vwp.direccion_residencia,
                        vwp.ciudad_residencia,
                        vwp.localidad_residencia,
                        vwp.upz_residencia,
                        vwp.barrio_residencia,
                        vwp.estrato,
                        vwp.correo_electronico,
                        vwp.numero_telefono,
                        vwp.numero_celular,
                        vwp.numero_resolucion,
                        vwp.fecha_resolucion,
                        vwp.monto_asignado,
                        vwp.codigo_presupuestal,
                        vwp.codigo_proyecto_inversion,
                        vwp.cdp,
                        vwp.crp
                FROM Viewparticipantes AS vwp  
                WHERE vwp.anio='" . $anio . "' AND vwp.estado_propuesta='Ganadora' " . $where_entidad . "
                ORDER BY vwp.convocatoria, vwp.nombre_propuesta, vwp.tipo_participante, vwp.representante DESC";

            $convocatorias = $app->modelsManager->executeQuery($sql_convocatorias);

            $documento = new Spreadsheet();
            $documento
                    ->getProperties()
                    ->setCreator("SICON")
                    ->setLastModifiedBy('SICON') // última vez modificado por
                    ->setTitle('Reporte de Ganadores')
                    ->setSubject('SICON')
                    ->setDescription('Reporte de Ganadores')
                    ->setKeywords('SICON')
                    ->setCategory('La categoría');

            $hoja = $documento->getActiveSheet();
            $hoja->setTitle("Reporte de Ganadores");

            //Cabezote de la tabla
            $hoja->setCellValueByColumnAndRow(1, 1, "Reporte de Ganadores");

            //Cabezote de la tabla
            $hoja->setCellValueByColumnAndRow(1, 2, "Fecha de corte");
            $hoja->setCellValueByColumnAndRow(2, 2, date("Y-m-d H:i:s"));

            //Cabezote de la tabla
            $hoja->setCellValueByColumnAndRow(1, 5, "Año");
            $hoja->setCellValueByColumnAndRow(2, 5, "Entidad");
            $hoja->setCellValueByColumnAndRow(3, 5, "Convocatoria");
            $hoja->setCellValueByColumnAndRow(4, 5, "Categoría");
            $hoja->setCellValueByColumnAndRow(5, 5, "Propuesta Nombre");
            $hoja->setCellValueByColumnAndRow(6, 5, "Código Propuesta");
            $hoja->setCellValueByColumnAndRow(7, 5, "Estado");
            $hoja->setCellValueByColumnAndRow(8, 5, "Número de resolución");
            $hoja->setCellValueByColumnAndRow(9, 5, "Fecha de resolución");
            $hoja->setCellValueByColumnAndRow(10, 5, "Monto asignado");
            $hoja->setCellValueByColumnAndRow(11, 5, "Código presupuestal");
            $hoja->setCellValueByColumnAndRow(12, 5, "Código proyecto de inversión");
            $hoja->setCellValueByColumnAndRow(13, 5, "CDP");
            $hoja->setCellValueByColumnAndRow(14, 5, "CRP");
            $hoja->setCellValueByColumnAndRow(15, 5, "Tipo Participante");
            $hoja->setCellValueByColumnAndRow(16, 5, "Tipo Rol");
            $hoja->setCellValueByColumnAndRow(17, 5, "Rol");
            $hoja->setCellValueByColumnAndRow(18, 5, "¿Representante?");
            $hoja->setCellValueByColumnAndRow(19, 5, "Tipo de documento");
            $hoja->setCellValueByColumnAndRow(20, 5, "Número de documento");
            $hoja->setCellValueByColumnAndRow(21, 5, "Nombres y Apellidos");
            $hoja->setCellValueByColumnAndRow(22, 5, "Fecha Nacimiento");
            $hoja->setCellValueByColumnAndRow(23, 5, "Sexo");
            $hoja->setCellValueByColumnAndRow(24, 5, "Dir. Residencia");
            $hoja->setCellValueByColumnAndRow(25, 5, "Ciudad de residencia");
            $hoja->setCellValueByColumnAndRow(26, 5, "Localidad Residencia");
            $hoja->setCellValueByColumnAndRow(27, 5, "Upz Residencia");
            $hoja->setCellValueByColumnAndRow(28, 5, "Barrio Residencia");
            $hoja->setCellValueByColumnAndRow(29, 5, "Estrato");
            $hoja->setCellValueByColumnAndRow(30, 5, "Correo electrónico");
            $hoja->setCellValueByColumnAndRow(31, 5, "Tel Fijo");
            $hoja->setCellValueByColumnAndRow(32, 5, "Tel Celular");

            //Registros de la base de datos
            $fila = 6;
            foreach ($convocatorias as $convocatoria) {
                $hoja->setCellValueByColumnAndRow(1, $fila, $convocatoria->anio);
                $hoja->setCellValueByColumnAndRow(2, $fila, $convocatoria->entidad);
                $hoja->setCellValueByColumnAndRow(3, $fila, $convocatoria->convocatoria);
                $hoja->setCellValueByColumnAndRow(4, $fila, $convocatoria->categoria);
                $hoja->setCellValueByColumnAndRow(5, $fila, $convocatoria->nombre_propuesta);
                $hoja->setCellValueByColumnAndRow(6, $fila, $convocatoria->codigo);
                $hoja->setCellValueByColumnAndRow(7, $fila, $convocatoria->estado_propuesta);
                $hoja->setCellValueByColumnAndRow(8, $fila, $convocatoria->numero_resolucion);
                $hoja->setCellValueByColumnAndRow(9, $fila, $convocatoria->fecha_resolucion);
                $hoja->setCellValueByColumnAndRow(10, $fila, $convocatoria->monto_asignado);
                $hoja->setCellValueByColumnAndRow(11, $fila, $convocatoria->codigo_presupuestal);
                $hoja->setCellValueByColumnAndRow(12, $fila, $convocatoria->codigo_proyecto_inversion);
                $hoja->setCellValueByColumnAndRow(13, $fila, $convocatoria->cdp);
                $hoja->setCellValueByColumnAndRow(14, $fila, $convocatoria->crp);
                $hoja->setCellValueByColumnAndRow(15, $fila, $convocatoria->tipo_participante);
                $hoja->setCellValueByColumnAndRow(16, $fila, $convocatoria->tipo_rol);
                $hoja->setCellValueByColumnAndRow(17, $fila, $convocatoria->rol);
                $value_representante = "No";
                if ($convocatoria->representante) {
                    $value_representante = "Sí";
                }
                $hoja->setCellValueByColumnAndRow(18, $fila, $value_representante);
                $hoja->setCellValueByColumnAndRow(19, $fila, $convocatoria->tipo_documento);
                $hoja->setCellValueByColumnAndRow(20, $fila, $convocatoria->numero_documento);
                $hoja->setCellValueByColumnAndRow(21, $fila, $convocatoria->primer_nombre . " " . $convocatoria->segundo_nombre . " " . $convocatoria->primer_apellido . " " . $convocatoria->segundo_apellido);
                $hoja->setCellValueByColumnAndRow(22, $fila, $convocatoria->fecha_nacimiento);
                $hoja->setCellValueByColumnAndRow(23, $fila, $convocatoria->sexo);
                $hoja->setCellValueByColumnAndRow(24, $fila, $convocatoria->direccion_residencia);
                $hoja->setCellValueByColumnAndRow(25, $fila, $convocatoria->ciudad_residencia);
                $hoja->setCellValueByColumnAndRow(26, $fila, $convocatoria->localidad_residencia);
                $hoja->setCellValueByColumnAndRow(27, $fila, $convocatoria->upz_residencia);
                $hoja->setCellValueByColumnAndRow(28, $fila, $convocatoria->barrio_residencia);
                $hoja->setCellValueByColumnAndRow(29, $fila, $convocatoria->estrato);
                $hoja->setCellValueByColumnAndRow(30, $fila, $convocatoria->correo_electronico);
                $hoja->setCellValueByColumnAndRow(31, $fila, $convocatoria->numero_telefono);
                $hoja->setCellValueByColumnAndRow(32, $fila, $convocatoria->numero_celular);
                $fila++;
            }

            $nombreDelDocumento = "reporte_ganadores.xlsx";

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
            $logger->error('"token":"{token}","user":"{user}","message":"Token caduco en el metodo reporte_listado_entidades_convocatorias_listado_jurados_xls al generar el reporte listado de la propuesta (' . $request->getPut('id') . ')', ['user' => "", 'token' => $request->getPut('token')]);
            $logger->close();
            echo "error_token";
        }
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias           
        $logger->error('"token":"{token}","user":"{user}","message":"Error metodo reporte_listado_entidades_convocatorias_listado_jurados_xls al generar el reporte listado de la propuesta (' . $request->getPut('id') . ')' . $ex->getMessage() . '"', ['user' => "", 'token' => $request->getPut('token')]);
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

function array_sort_by(&$arrIni, $col, $order = SORT_ASC) {
    $arrAux = array();
    foreach ($arrIni as $key => $row) {
        $arrAux[$key] = is_object($row) ? $arrAux[$key] = $row->$col : $row[$col];
        $arrAux[$key] = strtolower($arrAux[$key]);
    }
    array_multisort($arrAux, $order, $arrIni);
}

?>