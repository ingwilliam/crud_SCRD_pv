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



/*
 * 17-07-2020
 * Wilmer Gustavo Mogollón Duque
 * Se incorpora método juradospostulados/convocatoria/
 */
$app->post('/juradospostulados/convocatoria/{convocatoria:[0-9]+}', function ($id_convocatoria) use ($app, $config) {
    try {




        $convocatoria = Convocatorias::findFirst([' id = ' . $id_convocatoria]);
        
        
        $programa = Programas::findFirst(['id=' . $convocatoria->programa]);


        if ($convocatoria->convocatoria_padre_categoria == null) {
            $nombrec = $convocatoria->nombre;
        } else {
            $convocatoriapadre = Convocatorias::findFirst(
                            [
                                'id = ' . $convocatoria->convocatoria_padre_categoria
                                . ' AND active= true'
                            ]
            );
            $nombrec = $convocatoriapadre->nombre . " - " . $convocatoria->nombre;
        }

        $entidad = Entidades::findFirst(
                        [
                            'id = ' . $convocatoria->entidad
                            . ' AND active= true'
                        ]
        );

        echo '<div style="text-align: center;">';
        echo $entidad->nombre . "<br/>";
        echo $programa->nombre . "<br/>";
        echo $nombrec . "<br/>";
        echo 'Listado de jurados postulados';
        echo '<br/>';
        echo '<br/>';
        echo '</div>';



        $juradospostulados = Juradospostulados::find(
                        [
                            ' convocatoria = ' . $id_convocatoria
//                            . ' AND active= true'
                        ]
        );


        echo '<table border="1" cellpadding="2" cellspacing="2">
                        <tr style="background-color:#D8D8D8;color:#OOOOOO;">
                          <td>Número</td>
                          <td>Código</td>
                          <td>Nombre</td>
                          <td>Correo</td>
                          <td>Teléfono</td>
                        </tr>';
        
        

        $cont = 0;
        foreach ($juradospostulados as $juradopostulado) {
            $cont++;


            echo "<tr>
                    <td style='border: 1px solid black;'>" . $cont . "</td>
                    <td style='border: 1px solid black;'>" . $juradopostulado->Propuestas->codigo . "</td>
                    <td style='border: 1px solid black;'>" . $juradopostulado->Propuestas->Participantes->primer_nombre . " "
                . $juradopostulado->Propuestas->Participantes->segundo_nombre . " "
                . $juradopostulado->Propuestas->Participantes->primer_apellido . " "
                . $juradopostulado->Propuestas->Participantes->segundo_apellido . "</td>
                    <td style='border: 1px solid black;'>" . $juradopostulado->Propuestas->Participantes->correo_electronico . "</td>
                    <td style='border: 1px solid black;'>" . $juradopostulado->Propuestas->Participantes->numero_celular . "</td>
                  </tr>";

//            return json_encode($participante);
        }
        
        echo "</table>";


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