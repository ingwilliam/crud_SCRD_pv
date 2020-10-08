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
 * 22-07-2020
 * Wilmer Gustavo Mogollón Duque
 * Se incorpora método para generar acta de aceptación de notificación
 */

$app->post('/carta_aceptacion_notificacion/postulacion/{postulacion:[0-9]+}', function ($postulacion) use ($app, $config, $logger) {
    try {


        /*
         * Creo el objeto para traer los datos de la notificación
         */

        $notificacion = Juradosnotificaciones::findFirst(
                        [
                            'juradospostulado = ' . $postulacion
                            . ' AND active= true'
                        ]
        );

        $juradopostulado = Juradospostulados::findFirst(
                        [
                            'id = ' . $postulacion
                        ]
        );


        $convocatoria = Convocatorias::findFirst(
                        [
                            'id = ' . $juradopostulado->convocatoria
                            . ' AND active= true'
                        ]
        );

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

        $propuestashabilitadas = Propuestas::find(
                        [
                            'convocatoria = ' . $convocatoria->id
                            . ' AND active= true'
                            . ' AND estado in (24,33)'
                        ]
        );


        $participante = $juradopostulado->Propuestas->Participantes->primer_nombre . " "
                . $juradopostulado->Propuestas->Participantes->segundo_nombre . " "
                . $juradopostulado->Propuestas->Participantes->primer_apellido . " "
                . $juradopostulado->Propuestas->Participantes->segundo_apellido;


        echo '<div style="text-align: center;">';
        echo $entidad->nombre . "<br/>";
        echo $programa->nombre . "<br/>";
        echo $nombrec . "<br/>";
        echo '<br/>';
        echo '<br/>';
        echo 'A quien pueda interesar';
        echo '<br/>';
        echo '<br/>';
        echo '</div>';
        


        if ($notificacion == false) {
            echo "La postulación de " . $participante . " no ha sido notificada para ser jurado.";
        } else {
            $estado_notificacion = Estados::findFirst(" tipo_estado = 'jurado_notificaciones' AND id = " . $notificacion->estado)->nombre;

//            return $estado_notificacion;

            switch ($estado_notificacion) {
                case "Notificada":

                    echo $participante . " aún no ha dado respuesta a ésta notificación";

                    break;

                case "Declinada":

                    echo "El estado actual de la postulación de  " . $participante . " es declinada";

                    break;

                case "Aceptada":

                    echo "Yo " . $participante . ", identificado(a) con el documento de identidad " . $juradopostulado->Propuestas->Participantes->numero_documento . " manifiesto que:<br/><br/>";
                    echo " SI acepto la designación como jurado " . $juradopostulado->rol . " para la convocatoria denominada "
                    . $nombrec
                    . ' de '
                    . $entidad->nombre . ".<br/><br/>";

                    echo "Dicha convocatoria tiene un total de  " . $propuestashabilitadas->count() . " propuestas habilitadas para ser evaluadas por parte del jurado. "
                    . ' con un estímulo asignado por concepto de evaluación por la suma de: $'
                    . $notificacion->valor_estimulo . " pesos modeda corriente.<br/><br/>";


                    echo "Comprendo las siguientes facultades de los jurados del Programa Distrital de Estímulos, en adelante PDE, para la actual vigencia 
                            <ul>
                                <li>Efectuar la recomendación de selección teniendo en cuenta que la propuesta o propuestas ganadoras deben ser las
                                    que hayan obtenido el puntaje o puntajes más altos una vez realizada la deliberación, en todo caso respetando siempre
                                    el puntaje mínimo establecido para ser ganador en cada convocatoria del PDE. Su recomendación de selección será inapelable.</li>
                                <li>Recomendar que la convocatoria se declare desierta en su totalidad o en alguna de sus categorías o ciclos, si durante la
                                    deliberación encuentra por unanimidad que las propuestas evaluadas no ameritan el otorgamiento del estímulo. En este caso,
                                    el jurado expondrá las razones que tuvo en cuenta para tomar esta decisión.</li>
                                <li>Definir suplentes de los ganadores para los casos de inhabilidad, impedimento o renuncia.</li>
                                <li>Recomendar el otorgamiento de menciones a aquellas propuestas que considere. Esta decisión deberá quedar consignada
                                    en el acta de recomendación de ganadores.</li>
                                <li>Realizar recomendaciones a las propuestas ganadoras para que sean tenidas en cuenta durante la ejecución, siempre y cuando
                                    éstas no modifiquen el propósito y alcance.</li>
                            </ul>
                            <br/>";

                    echo "Acepto los compromisos que se relacionan a continuación y que hacen parte de las condiciones generales de participación del PDE.";


                    echo "<ul>
                                <li>Leer detenidamente las condiciones generales de participación del PDE y los requisitos específicos de la
                                    convocatoria de la cual es jurado.</li>
                                <li>Una vez recibido el acceso al material para la evaluación, verificar que se encuentra la totalidad de las propuestas asignadas
                                    e informar cualquier inconsistencia a la entidad responsable de la convocatoria.</li>
                                <li>Declararse impedido, mediante comunicación escrita, con mínimo cinco (5) días hábiles antes de la fecha de deliberación, respecto
                                    de las propuestas frente a los cuales identifique la existencia de inhabilidad o conflicto de intereses, o frente aquellas en las
                                    que considere que no puede emitir un concepto objetivo.</li>
                                <li>Asistir a las reuniones, audiciones, visitas de campo y demás actividades programadas por la entidad responsable de la convocatoria,
                                    durante el proceso de evaluación, en el lugar, fecha y hora que le sean indicados.</li>
                                <li>Leer y evaluar, previo a la deliberación, las propuestas de la convocatoria para la cual fue seleccionado como jurado.</li>
                                <li>Presentar por escrito a la entidad encargada, con la debida anterioridad, las consultas y solicitudes
                                    de aclaración sobre la convocatoria que debe evaluar (en ningún caso la entidad resolverá inquietudes formuladas verbalmente).</li>
                                <li>Tener en cuenta los criterios de evaluación establecidos para cada convocatoria y realizar la selección de
                                    conformidad con los principios de objetividad, transparencia y autonomía.</li>
                                <li>Diligenciar una planilla de evaluación por cada obra o propuesta recibida, emitiendo un concepto técnico
                                    por cada criterio de valoración o una recomendación que retroalimente al participante.</li>
                                <li>Elaborar, sustentar y firmar el acta de recomendación de ganadores de la convocatoria que evaluó.</li>
                                <li>Acudir ante la entidad y presentar por escrito las aclaraciones que le sean requeridas, en el evento
                                    de presentarse solicitudes efectuadas por terceros, organismos de control o participantes.</li>
                                <li>Cumplir éticamente los deberes encomendados como jurado, procurando siempre la observancia de los
                                    principios de igualdad, buena fe y dignidad humana consignados en la Constitución</li>
                                <li>Realizar un informe final por cada una de las convocatorias en las que participó como evaluador,
                                    este documento deberá incluir recomendaciones para el fortalecimiento del PDE.</li>
                                <li>Mantener absoluta confidencialidad en el manejo de la información durante todo el proceso evaluación.</li>
                                <li>Abstenerse de hacer uso de la información a que accede en su condición de jurado, para cualquier objetivo
                                    diferente de la evaluación, respetando siempre los derechos de autor del participante.</li>
                            </ul>";

                    echo "Se entregará el 100% del valor determinado como reconocimiento económico, una vez el jurado haya cumplido con todas y cada una de sus
                                    obligaciones, previa entrega de la siguiente documentación y de la certificación expedida por la entidad encargada de la convocatoria:
                                    Fotocopia del Certificado de Registro Único Tributario - RUT (para nacionales). Certificación bancaria a nombre del jurado, no mayor a
                                    treinta (30) días de expedida, en la que conste que la cuenta está activa, cuál es su tipo (ahorros o corriente) y número. En caso de no
                                    tener cuenta, deberá consultar a la entidad los mecanismos establecidos para otras formas de pago. Certificación de afiliación activa a
                                    salud correspondiente al mes en el que se tramita el pago. En el caso de jurados extranjeros no residentes en Colombia, solo se solicitará
                                    la certificación bancaria a nombre el jurado, no mayor a treinta (30) días de expedida. Los jurados extranjeros residentes en Colombia
                                    deberán presentar los mismos documentos que los jurados nacionales.<br/><br/>";

                    echo "Nota: En caso de que un jurado incumpla con alguno de los compromisos estipulados en el presente documento, la entidad otorgante lo
                                    requerirá para que dé las explicaciones pertinentes. De no atender dicho requerimiento dentro de los cinco (5) días hábiles siguientes o
                                    no cumplir los compromisos acordados, la entidad procederá a retirar el estímulo de manera unilateral mediante acto administrativo, declarando
                                    su incumplimiento y se establecerá que el ganador no podrá participar por el término de los dos (2) años siguientes en las convocatorias del PDE.<br/><br/>";


                    echo "Fecha de aceptación: ".$notificacion->fecha_aceptacion;

                    break;
                
                case "Rechazada":

                    echo "Yo " . $participante . ", identificado(a) con el documento de identidad " . $juradopostulado->Propuestas->Participantes->numero_documento . " manifiesto que:<br/><br/>";
                    echo "Rechazo la designación como jurado " . $juradopostulado->rol . " para la convocatoria denominada "
                    . $nombrec
                    . ' de '
                    . $entidad->nombre . ".<br/><br/>";


                    echo "Fecha de rechazo: ".$notificacion->fecha_rechazo;

                    break;

                default:
                    break;
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