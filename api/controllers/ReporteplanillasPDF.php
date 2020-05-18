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



$app->get('/evaluacionpropuestas/ronda/{ronda:[0-9]+}', function ($ronda) use ($app, $config) {
    try {


        //ordenar
        $phql = 'SELECT
                  distinct (p.id), p.*
               FROM
                  Propuestas p
                  inner join Evaluacionpropuestas ep ON p.id = ep.propuesta
              WHERE
              ep.ronda = ' . $ronda;

        $rs = $this->modelsManager->createQuery($phql)->execute();

        //  echo json_encode($rs->count());

        foreach ($rs as $row) {
            //echo json_encode($rs);

            $evaluacionpropuestas = Evaluacionpropuestas::find(
                            [
                                ' propuesta = ' . $row->p->id
                                . ' AND ronda = ' . $ronda
                            ]
            );



            $participantes = Participantes::findFirst('id = ' . $row->p->participante);

            echo "<b>Código propuesta: " . $row->p->codigo . "<br>";
            echo "<b>Nombre Participante: " . $participantes->primer_nombre . " "
            . $participantes->segundo_nombre . " "
            . $participantes->primer_apellido . " "
            . $participantes->primer_apellido . "<br>";
            echo "<b>Nombre Propuesta: " . $row->p->nombre . "<br>";


            foreach ($evaluacionpropuestas as $evaluacionpropuesta) {

                echo "Total evaluación: " . $evaluacionpropuesta->total . "</b></br></br>";

                //criterios de la ronda

                $criterios = Convocatoriasrondascriterios::find(
                                [
                                    'convocatoria_ronda = ' . $ronda
                                    . ' AND active= true',
                                    'order' => 'orden ASC'
                                ]
                );

                echo "<table style='border: 1px solid black;'>
                        <tr >
                          <td style='border: 1px solid black;background-color:#00FF00'>Criterio</td>
                          <td style='border: 1px solid black;background-color:#00FF00'>Puntaje máximo</td>
                          <td style='border: 1px solid black;background-color:#00FF00'>Calificación</td>
                          <td style='border: 1px solid black;background-color:#00FF00'>Observación</td>
                        </tr>";

                foreach ($criterios as $criterio) {

                    $evaluacioncriterio = Evaluacioncriterios::findFirst(
                                    [
                                        'evaluacionpropuesta = ' . $evaluacionpropuesta->id
                                        . ' AND criterio = ' . $criterio->id
                                        . ' AND active= true'
                                    ]
                    );

                    echo "<tr>
                            <td style='border: 1px solid black;'>" . $criterio->descripcion_criterio . "</td>
                            <td style='border: 1px solid black;'>" . $criterio->puntaje_maximo . "</td>
                            <td style='border: 1px solid black;'>" . $evaluacioncriterio->puntaje . "</td>
                            <td style='border: 1px solid black;'>" . $evaluacioncriterio->observacion . "</td>
                          </tr>";
                }

                echo "</table>";

                $evaluador = Evaluadores::findFirst('id = ' . $evaluacionpropuesta->evaluador);
                $juradopostulado = Juradospostulados::findFirst('id = ' . $evaluador->juradopostulado);

                echo "</br></br>";
                echo "<b>Código del jurado :" . $juradopostulado->Propuestas->codigo;
                echo "<br>Nombre del jurado:" . $juradopostulado->Propuestas->Participantes->primer_nombre . " " . $juradopostulado->Propuestas->Participantes->segundo_nombre . " " . $juradopostulado->Propuestas->Participantes->primer_apellido . " " . $juradopostulado->Propuestas->Participantes->segundo_apellido;
                echo "</b></br></br>";
                echo "<hr>";
            }
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