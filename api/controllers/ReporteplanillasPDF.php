<?php

error_reporting(E_ALL);
ini_set('display_errors', '1');

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
use PhpOffice\PhpWord\Style\Language;
use Phalcon\Mvc\Model\Query;
use Phalcon\Db\RawValue;

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



$app->post('/evaluacionpropuestas/ronda/{ronda:[0-9]+}', function ($ronda) use ($app, $config) {
    try {

        $request = new Request();        
        $fase='Evaluación';
        
        if($request->get("deliberacion")=="true")
        {
            $fase='Deliberación';
        }
        
        $in_codigos="";
        
        if($request->get("codigos")!="")
        {
            $array_codigos= explode(",",$request->get("codigos"));                        
            foreach($array_codigos as $clave)
            {
                $in_codigos=$in_codigos.",'".$clave."'";
            }
            
            $in_codigos = trim($in_codigos, ",");
            
            $in_codigos=" AND p.codigo IN (".$in_codigos.")";
            
        }
        
        $where = " AND ep.fase='".$fase."'".$in_codigos;
        
        //ordenar
        $phql = 'SELECT
                  distinct (p.id), p.*
               FROM
                  Propuestas p
                  inner join Evaluacionpropuestas ep ON p.id = ep.propuesta
              WHERE
              ep.ronda = ' . $ronda." ".$where;

        $rs = $this->modelsManager->createQuery($phql)->execute();

        //  echo json_encode($rs->count());

        foreach ($rs as $row) {
            //echo json_encode($rs);
            
            $evaluacionpropuestas = Evaluacionpropuestas::find(
                            [
                                ' propuesta = ' . $row->p->id
                                . ' AND ronda = ' . $ronda
                                . ' AND fase = "' . $fase.'"'
                            ]
            );
            
            $participantes = Participantes::findFirst('id = ' . $row->p->participante);

            echo "<b>Código propuesta:</b>" . $row->p->codigo . "<br/>";
            echo "<b>Nombre Participante:</b>" . $participantes->primer_nombre . " "
            . $participantes->segundo_nombre . " "
            . $participantes->primer_apellido . " "
            . $participantes->primer_apellido . "<br/>";
            echo "<b>Nombre Propuesta:</b>" . $row->p->nombre . "<br/><br/>";
            
            echo "<b>CRITERIOS DE EVALUACIÓN</b><br/><br/>";


            foreach ($evaluacionpropuestas as $evaluacionpropuesta) {

                //criterios de la ronda

                $criterios = Convocatoriasrondascriterios::find(
                                [
                                    'convocatoria_ronda = ' . $ronda
                                    . ' AND active= true',
                                    'order' => 'orden ASC'
                                ]
                );
                

                echo '<table border="1" cellpadding="2" cellspacing="2">
                        <tr style="background-color:#D8D8D8;color:#OOOOOO;">
                          <td>Número</td>
                          <td>Criterio</td>
                          <td>Puntaje máximo</td>
                          <td>Calificación</td>
                          <td>Observación</td>
                        </tr>';
                
                
                /*
                 * 10-06-2020
                 * Wilmer Gustavo Mogollón Duque
                 * Se agrega numeración a los criterios
                 */
                
                $cont = 0;

                foreach ($criterios as $criterio) {
                    $cont++;

                    $evaluacioncriterio = Evaluacioncriterios::findFirst(
                                    [
                                        'evaluacionpropuesta = ' . $evaluacionpropuesta->id
                                        . ' AND criterio = ' . $criterio->id
                                        . ' AND active= true'
                                    ]
                    );
                    
                    if(isset($evaluacioncriterio->puntaje)){
                        $puntaje=$evaluacioncriterio->puntaje;
                    }else{
                        $puntaje="";
                    }
                    
                    if(isset($evaluacioncriterio->observacion)){
                        $observacion=$evaluacioncriterio->observacion;
                    }else{
                        $observacion="";
                    }
                    
                    

                    echo '<tr>
                            <td>' . $cont . '</td>
                            <td>' . $criterio->descripcion_criterio . '</td>
                            <td>' . $criterio->puntaje_maximo . '</td>
                            <td>' . $puntaje . '</td>
                            <td>' . $observacion . '</td>
                          </tr>';
                }

                echo '</table>';

                $evaluador = Evaluadores::findFirst('id = ' . $evaluacionpropuesta->evaluador);
                $juradopostulado = Juradospostulados::findFirst('id = ' . $evaluador->juradopostulado);

                echo "<b>Total evaluación:</b>" . $evaluacionpropuesta->total;                
                echo "<br/>";
                echo "<b>Código del jurado:</b>" . $juradopostulado->Propuestas->codigo;
                echo "<br/>";
                echo "<b>Nombre del jurado:</b>" . $juradopostulado->Propuestas->Participantes->primer_nombre . " " . $juradopostulado->Propuestas->Participantes->segundo_nombre . " " . $juradopostulado->Propuestas->Participantes->primer_apellido . " " . $juradopostulado->Propuestas->Participantes->segundo_apellido;
                echo "<br/><br/>";                
                echo "<hr/>";
                echo "<br/><br/>";
            }
            
            echo "<br/><br/>";
        }
    } catch (Exception $ex) {
        //return "error_metodo";
        //Para auditoria en versión de pruebas
        return "error_metodo" . $ex->getMessage() . json_encode($ex->getTrace());
    }
}
);



try {
    // Gestionar la consulta
    $app->handle();
} catch (\Exception $e) {
    echo 'Excepción: ', $e->getMessage();
}
?>