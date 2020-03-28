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
        $logger->info('"token":"{token}","user":"{user}","message":"Ingresa al metodo reporte_listado_propuesta_rechazados_habilitados para generar reporte de listado de inscripcion de la propuesta (' . $request->getPut('id') . ')"', ['user' => '', 'token' => $request->getPut('token')]);

        //Si el token existe y esta activo entra a realizar la tabla
        if ($token_actual > 0) {


            require_once("../library/phpspreadsheet/autoload.php");

            $entidad = Entidades::findFirst($request->getPut('entidad'));
            $anio = $request->getPut('anio');

            //Genero reporte propuestas por estado
            $sql_convocatorias = "
                SELECT c.convocatoria,c.categoria,es.nombre AS estado,COUNT(p.id) AS total FROM Viewconvocatorias AS c
                INNER JOIN Propuestas AS p ON p.convocatoria= c.id
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

$app->post('/reporte_listado_entidades_convocatorias_estado', function () use ($app, $config, $logger) {

//Instancio los objetos que se van a manejar
    $request = new Request();
    $tokens = new Tokens();

    try {

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPut('token'));

        //Registro la accion en el log de convocatorias
        $logger->info('"token":"{token}","user":"{user}","message":"Ingresa al metodo reporte_listado_propuesta_rechazados_habilitados para generar reporte de listado de inscripcion de la propuesta (' . $request->getPut('id') . ')"', ['user' => '', 'token' => $request->getPut('token')]);

        //Si el token existe y esta activo entra a realizar la tabla
        if ($token_actual > 0) {
            
            //Consulto lo necesario
            $user_current = json_decode($token_actual->user_current, true);
            $entidad = Entidades::findFirst($request->getPut('entidad'));

            //Genero reporte propuestas por estado
            $sql_convocatorias = "
                        SELECT c.nombre AS convocatoria,es.nombre AS estado,COUNT(p.id) AS total FROM Convocatorias AS c
                        INNER JOIN Propuestas AS p ON p.convocatoria= c.id
                        INNER JOIN Estados AS es ON es.id= p.estado
                        WHERE c.anio='".$request->getPut('anio')."' AND c.entidad=".$request->getPut('entidad')." AND c.active=TRUE AND c.convocatoria_padre_categoria IS NULL AND c.tiene_categorias=FALSE AND c.modalidad <> 2 AND c.estado IN (5, 6)
                        GROUP BY 1,2
                        ORDER BY 1,2,3";
            
            $convocatorias = $app->modelsManager->executeQuery($sql_convocatorias);

            $html_propuestas = "";
            foreach ($convocatorias as $convocatoria) {
                $html_propuestas = $html_propuestas . "<tr>";
                $html_propuestas = $html_propuestas . "<td>" . $convocatoria->convocatoria . "</td>";
                $html_propuestas = $html_propuestas . "<td></td>";
                $html_propuestas = $html_propuestas . "<td>" . $convocatoria->estado . "</td>";
                $html_propuestas = $html_propuestas . "<td>" . $convocatoria->total . "</td>";                
                $html_propuestas = $html_propuestas . "</tr>";
            }
            
            //Genero reporte propuestas por estado
            $sql_convocatorias_categorias = "
                        SELECT cat.nombre AS convocatoria,c.nombre AS categoria,es.nombre AS estado,COUNT(p.id) AS total FROM Convocatorias AS c
                        INNER JOIN Propuestas AS p ON p.convocatoria= c.id
                        INNER JOIN convocatorias AS cat ON cat.id= c.convocatoria_padre_categoria
                        INNER JOIN Estados AS es ON es.id= p.estado
                        WHERE c.anio='".$request->getPut('anio')."' AND c.entidad=".$request->getPut('entidad')." AND c.active=TRUE AND c.convocatoria_padre_categoria IS NOT NULL AND c.tiene_categorias=TRUE AND c.modalidad <> 2 AND c.estado IN (5, 6)
                        GROUP BY 1,2,3
                        ORDER BY 1,2";
            
            $convocatorias_categorias = $app->modelsManager->executeQuery($sql_convocatorias_categorias);
            
            foreach ($convocatorias_categorias as $convocatoria_categoria) {
                $html_propuestas = $html_propuestas . "<tr>";
                $html_propuestas = $html_propuestas . "<td>" . $convocatoria_categoria->convocatoria . "</td>";
                $html_propuestas = $html_propuestas . "<td>" . $convocatoria_categoria->categoria . "</td>";
                $html_propuestas = $html_propuestas . "<td>" . $convocatoria->estado . "</td>";
                $html_propuestas = $html_propuestas . "<td>" . $convocatoria->total . "</td>";                
                $html_propuestas = $html_propuestas . "</tr>";
            }
            
            
            
            
                
                $html='<table border="1" cellpadding="2" cellspacing="2" nobr="true">
                    <tr>
                        <td colspan="4" align="center">Estado de propuestas</td>
                    </tr>
                    <tr>
                        <td colspan="4" align="center"> Fecha de corte '.date("Y-m-d H:i:s").'</td>
                    </tr>
                    <tr>
                        <td colspan="2">Año: '.$request->getPut('anio').'</td>
                        <td colspan="2">Entidad: '.$entidad->descripcion.'</td>
                    </tr>                                    
                    <tr style="background-color:#BDBDBD;color:#OOOOOO;">
                        <td align="center">Convocatoria</td>
                        <td align="center">Categoria</td>
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



$app->post('/reporte_convocatorias_cerrar_xls', function () use ($app, $config, $logger) {

    //Instancio los objetos que se van a manejar
    $request = new Request();
    $tokens = new Tokens();

    try {
        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPut('token'));

        //Registro la accion en el log de convocatorias
        $logger->info('"token":"{token}","user":"{user}","message":"Ingresa al metodo reporte_listado_propuesta_rechazados_habilitados para generar reporte de listado de inscripcion de la propuesta (' . $request->getPut('id') . ')"', ['user' => '', 'token' => $request->getPut('token')]);

        //Si el token existe y esta activo entra a realizar la tabla
        if ($token_actual > 0) {


            require_once("../library/phpspreadsheet/autoload.php");

            $entidad = Entidades::findFirst($request->getPut('entidad'));
            $anio = $request->getPut('anio');

            //Genero reporte propuestas por estado
            $sql_convocatorias = "
            SELECT vc.convocatoria,vc.categoria,cc.fecha_fin FROM Viewconvocatorias AS vc
            INNER JOIN Convocatoriascronogramas AS cc ON cc.convocatoria=vc.id
            WHERE vc.anio='".$anio."' AND cc.tipo_evento=12 AND vc.entidad='".$entidad->id."'
            ORDER BY vc.convocatoria,cc.fecha_inicio";
            
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

$app->post('/reporte_listado_entidades_convocatorias_cerrar', function () use ($app, $config, $logger) {

//Instancio los objetos que se van a manejar
    $request = new Request();
    $tokens = new Tokens();

    try {

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPut('token'));

        //Registro la accion en el log de convocatorias
        $logger->info('"token":"{token}","user":"{user}","message":"Ingresa al metodo reporte_listado_propuesta_rechazados_habilitados para generar reporte de listado de inscripcion de la propuesta (' . $request->getPut('id') . ')"', ['user' => '', 'token' => $request->getPut('token')]);

        //Si el token existe y esta activo entra a realizar la tabla
        if ($token_actual > 0) {
            
            //Consulto lo necesario
            $user_current = json_decode($token_actual->user_current, true);
            $entidad = Entidades::findFirst($request->getPut('entidad'));

            $html_propuestas = "";
            
            //Genero reporte propuestas por estado
            $sql_convocatorias = "
                        SELECT vc.convocatoria,vc.categoria,cc.fecha_fin FROM Viewconvocatorias AS vc
                        INNER JOIN Convocatoriascronogramas AS cc ON cc.convocatoria=vc.id
                        WHERE vc.anio='".$request->getPut('anio')."' AND cc.tipo_evento=12 AND vc.entidad='".$request->getPut('entidad')."'
                        ORDER BY vc.convocatoria,cc.fecha_inicio";
            
            $convocatorias = $app->modelsManager->executeQuery($sql_convocatorias);
            
            foreach ($convocatorias as $convocatoria) {
                $html_propuestas = $html_propuestas . "<tr>";
                $html_propuestas = $html_propuestas . '<td colspan="2">' . $convocatoria->convocatoria . '</td>';
                $html_propuestas = $html_propuestas . "<td>" . $convocatoria->categoria . "</td>";
                $html_propuestas = $html_propuestas . "<td>" . $convocatoria->fecha_fin . "</td>";                
                $html_propuestas = $html_propuestas . "</tr>";
            }
            
            
            
            
                
                $html='<table border="1" cellpadding="2" cellspacing="2" nobr="true">
                    <tr>
                        <td colspan="4" align="center"> Convocatorias próximas a cerrar </td>
                    </tr>
                    <tr>
                        <td colspan="4" align="center"> Fecha de corte '.date("Y-m-d H:i:s").'</td>
                    </tr>
                    <tr>
                        <td colspan="2">Año: '.$request->getPut('anio').'</td>
                        <td colspan="2">Entidad: '.$entidad->descripcion.'</td>
                    </tr>                                    
                    <tr style="background-color:#BDBDBD;color:#OOOOOO;">
                        <td align="center" colspan="2">Convocatoria</td>
                        <td align="center">Categoria</td>
                        <td align="center">Fecha de cierre</td>                                              
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
        if ($token_actual > 0) {
            
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
                        WHERE c.anio = ".$request->getPut('anio')." AND c.entidad='".$request->getPut('entidad')."' 
                        GROUP BY 1,2,3,4,5
                        ORDER  BY 1,2,3,4,5";
            
            $convocatorias = $app->modelsManager->executeQuery($sql_convocatorias);
            
            foreach ($convocatorias as $convocatoria) {
                $nombre_convocatoria=$convocatoria->cnombre;
                if($convocatoria->cpnombre)
                {
                    $nombre_convocatoria=$convocatoria->cpnombre." - ".$convocatoria->cnombre;
                }
                
                $html_propuestas = $html_propuestas . "<tr>";
                $html_propuestas = $html_propuestas . '<td>' . $nombre_convocatoria . '</td>';
                $html_propuestas = $html_propuestas . "<td>" . $convocatoria->modalidad_participa . "</td>";
                $html_propuestas = $html_propuestas . "<td>" . $convocatoria->estado_postulacion . "</td>";                
                $html_propuestas = $html_propuestas . "<td>" . $convocatoria->cantidad . "</td>";                
                $html_propuestas = $html_propuestas . "</tr>";
            }
            
                $html='<table border="1" cellpadding="2" cellspacing="2" nobr="true">
                    <tr>
                        <td colspan="4" align="center"> Cantidad de jurados por convocatoria </td>
                    </tr>
                    <tr>
                        <td colspan="4" align="center"> Fecha de corte '.date("Y-m-d H:i:s").'</td>
                    </tr>
                    <tr>
                        <td colspan="2">Año: '.$request->getPut('anio').'</td>
                        <td colspan="2">Entidad: '.$entidad->descripcion.'</td>
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
        $logger->info('"token":"{token}","user":"{user}","message":"Ingresa al metodo reporte_listado_propuesta_rechazados_habilitados para generar reporte de listado de inscripcion de la propuesta (' . $request->getPut('id') . ')"', ['user' => '', 'token' => $request->getPut('token')]);

        //Si el token existe y esta activo entra a realizar la tabla
        if ($token_actual > 0) {


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
            WHERE c.anio = ".$anio." AND c.entidad='".$entidad->id."' 
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
                $nombre_convocatoria=$convocatoria->cnombre;
                if($convocatoria->cpnombre)
                {
                    $nombre_convocatoria=$convocatoria->cpnombre." - ".$convocatoria->cnombre;
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

try {
    // Gestionar la consulta
    $app->handle();
} catch (\Exception $e) {
    echo 'Excepción: ', $e->getMessage();
}
?>