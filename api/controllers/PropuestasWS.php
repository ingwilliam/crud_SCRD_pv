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
        "host" => $config->database->host,"port" => $config->database->port,
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

$app->post('/certificacion', function () use ($app, $config, $logger) {

//Instancio los objetos que se van a manejar
    $request = new Request();
    $tokens = new Tokens();

    try {

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPut('token'));

        //Registro la accion en el log de convocatorias
        $logger->info('"token":"{token}","user":"{user}","message":"Ingresa al metodo certificacion para generar reporte de la propuesta (' . $request->getPut('id') . ')"', ['user' => '', 'token' => $request->getPut('token')]);

        //Si el token existe y esta activo entra a realizar la tabla
        //if (isset($token_actual->id)) {
        if (true) {

            $propuesta = Propuestas::findFirst("codigo = '".$request->getPut('id')."'");

            if (isset($propuesta->id)) {
                
                //Valido si fue ganador y que contenga las fechas de ejecución de cada propuesta
                $ganador=false;                
                if($propuesta->estado==34 && $propuesta->fecha_inicio_ejecucion != null && $propuesta->fecha_fin_ejecucion != null && $propuesta->nombre_resolucion != "")
                {
                    $ganador=true;
                }
                
                //Consulto quien firma por entidad                
                $sql_firma = "
                    select u.* from Usuarios as u
                    inner join Usuariosentidades as ue on ue.usuario=u.id
                    where u.certifica=true and ue.entidad=".$propuesta->getConvocatorias()->entidad;

                $usuarios_firmas = $app->modelsManager->executeQuery($sql_firma);
                
                $cargo_firma="";
                $nombre_firma="";
                foreach ($usuarios_firmas as $usuario_firma) {
                    $cargo_firma=$usuario_firma->cargo;
                    $nombre_firma=$usuario_firma->primer_nombre." ".$usuario_firma->segundo_nombre." ".$usuario_firma->primer_apellido." ".$usuario_firma->segundo_apellido;
                }
                
                //Meses
                $meses = array("Enero","Febrero","Marzo","Abril","Mayo","Junio","Julio","Agosto","Septiembre","Octubre","Noviembre","Diciembre");
                
                //Dia en letras
                $formatter = new NumeroALetras();
                $dia_actual = mb_strtolower($formatter->toMoney(date("d")));
                
                //Nombre del participante
                $nombre_participante = $propuesta->getParticipantes()->primer_nombre." ".$propuesta->getParticipantes()->segundo_nombre." ".$propuesta->getParticipantes()->primer_apellido." ".$propuesta->getParticipantes()->segundo_apellido;
                $documento_participante = $propuesta->getParticipantes()->numero_documento;
                $tipo_documento_participante = $propuesta->getParticipantes()->getTiposdocumentos()->descripcion;
                $tipo_participante_participante = $propuesta->getParticipantes()->getUsuariosperfiles()->getPerfiles()->nombre;
                
                $valor_letras = mb_strtolower($formatter->toMoney($propuesta->monto_asignado));
                
                if($ganador)
                {
                    //como persona natural
                    if($request->getPut('tp')=='PN')
                    {
                        $html = '
                                <br/>
                                <br/>
                                <br/>
                                <div style="text-align:center"><b>LA SUSCRITA '.mb_strtoupper($cargo_firma).' DE LA <br/>'.mb_strtoupper($propuesta->getConvocatorias()->getEntidades()->descripcion).' - '.mb_strtoupper($propuesta->getConvocatorias()->getEntidades()->nombre).'</b></div>
                                <br/>
                                <br/>
                                <div style="text-align:center"><b>CERTIFICA QUE:</b></div>
                                <br/>
                                <br/>
                                <div style="text-align: justify">Según Resolución No. 
                                '.$propuesta->numero_resolucion.' del 
                                '.date("d",strtotime($propuesta->fecha_resolucion)).' de '.$meses[date("n",strtotime($propuesta->fecha_resolucion))-1].' de '.date("Y",strtotime($propuesta->fecha_resolucion)).'
                                “'.$propuesta->nombre_resolucion.'”, 
                                la '.$tipo_participante_participante.' 
                                '.$nombre_participante.' identificado(a) con 
                                '.$tipo_documento_participante.' No. '.$documento_participante.',                                 
                                fue seleccionado(a) como ganador de la citada convocatoria por la propuesta 
                                “'.$propuesta->nombre.'”, (Código '.$propuesta->codigo.'), 
                                y se le otorgó como valor del estímulo económico la suma de 
                                '.mb_strtoupper($valor_letras).' DE PESOS ($'.number_format($propuesta->monto_asignado).') M/CTE, 
                                ejecutados en el proyecto desde 
                                el '.date("d",strtotime($propuesta->fecha_inicio_ejecucion)).' de '.$meses[date("n",strtotime($propuesta->fecha_inicio_ejecucion))-1].' del '.date("Y",strtotime($propuesta->fecha_inicio_ejecucion)).'  al '.date("d",strtotime($propuesta->fecha_fin_ejecucion)).' de '.$meses[date("n",strtotime($propuesta->fecha_fin_ejecucion))-1].' del '.date("Y",strtotime($propuesta->fecha_fin_ejecucion)).' 
                                y cumpliendo con los deberes como ganadores del 
                                '.$propuesta->getConvocatorias()->getProgramas()->nombre.'
                                </div>                                
                                <br/>
                                <div>La presente certificación se expide en Bogotá D.C., a los '.$dia_actual.' ('.date("d").') días del mes de '.$meses[date('n')-1].' de '.date("Y").'.</div>
                                <br/><br/>
                                <div style="text-align:center"><img src="http://localhost/report_SCRD_pv/images/firma_'.$propuesta->getConvocatorias()->getEntidades()->nombre.'.png"  width="100" height="70" border="0" /><br/>
                                <b>'.$nombre_firma.'</b><br/>
                                '.$cargo_firma.'</div>
                                ';                                                                                  
                    }

                    //como persona natural
                    if($request->getPut('tp')=='PJ')
                    {
                        
                        //Integrantes de la PJ
                        $conditions = ['id' => $propuesta->participante, 'participante_padre' => $propuesta->participante, 'tipo' => 'Junta', 'active' => true];
                        $consulta_integrantes = Participantes::find(([
                                    'conditions' => 'id<>:id: AND participante_padre=:participante_padre: AND tipo=:tipo: AND active=:active:',
                                    'bind' => $conditions,
                                    "order" => 'representante DESC'
                        ]));

                        $i = 1;
                        $html_integrantes = "";
                        foreach ($consulta_integrantes as $integrante) {
                            $value_representante="No";
                            if($integrante->representante)
                            {
                                $value_representante="Sí";
                            }
                            
                            $html_integrantes = $html_integrantes . "<tr>";
                            $html_integrantes = $html_integrantes . "<td>" . $integrante->primer_nombre . " " . $integrante->segundo_nombre . " ". $integrante->primer_apellido . " " . $integrante->segundo_apellido . "</td>";
                            $html_integrantes = $html_integrantes . "<td>" . $integrante->getTiposdocumentos()->nombre ." ".$integrante->numero_documento. "</td>";
                            $html_integrantes = $html_integrantes . "<td>" . $integrante->rol . "</td>";
                            $html_integrantes = $html_integrantes . "</tr>";                            
                        }
                        
                        
                        $html = '
                                <br/>
                                <br/>
                                <br/>
                                <div style="text-align:center"><b>LA SUSCRITA '.mb_strtoupper($cargo_firma).' DE LA <br/>'.mb_strtoupper($propuesta->getConvocatorias()->getEntidades()->descripcion).' - '.mb_strtoupper($propuesta->getConvocatorias()->getEntidades()->nombre).'</b></div>
                                <br/>
                                <br/>
                                <div style="text-align:center"><b>CERTIFICA QUE:</b></div>
                                <br/>
                                <br/>
                                <div style="text-align: justify">Según Resolución No. 
                                '.$propuesta->numero_resolucion.' del 
                                '.date("d",strtotime($propuesta->fecha_resolucion)).' de '.$meses[date("n",strtotime($propuesta->fecha_resolucion))-1].' de '.date("Y",strtotime($propuesta->fecha_resolucion)).'
                                “'.$propuesta->nombre_resolucion.'”, 
                                la '.$tipo_participante_participante.' 
                                “'.$nombre_participante.'” 
                                fue seleccionada como una de las ganadoras de la citada convocatoria por la propuesta 
                                “'.$propuesta->nombre.'”, 
                                y se le otorgó como valor del estímulo económico la suma de 
                                '.mb_strtoupper($valor_letras).' DE PESOS ($'.number_format($propuesta->monto_asignado).') M/CTE, 
                                ejecutados en el proyecto desde 
                                el '.date("d",strtotime($propuesta->fecha_inicio_ejecucion)).' de '.$meses[date("n",strtotime($propuesta->fecha_inicio_ejecucion))-1].' del '.date("Y",strtotime($propuesta->fecha_inicio_ejecucion)).'  al '.date("d",strtotime($propuesta->fecha_fin_ejecucion)).' de '.$meses[date("n",strtotime($propuesta->fecha_fin_ejecucion))-1].' del '.date("Y",strtotime($propuesta->fecha_fin_ejecucion)).' 
                                y cumpliendo con los deberes como ganadores del 
                                '.$propuesta->getConvocatorias()->getProgramas()->nombre.'
                                </div>
                                <div style="text-align: justify">De acuerdo con la inscripción realizada por la 
                                '.$tipo_participante_participante.'  
                                en el Sistema de Convocatorias Públicas del 
                                Sector Cultura, Recreación y Deporte 
                                -SICON se certifica la siguiente participación:
                                </div>
                                <br/>
                                <table border="1">
                                    <tr>
                                        <td bgcolor="#cccccc" align="center">Nombre</td>
                                        <td bgcolor="#cccccc" align="center">No Identificación</td>                            
                                        <td bgcolor="#cccccc" align="center">Rol</td>                            
                                    </tr>
                                    '.$html_integrantes.'
                                </table>
                                <br/>
                                <div>La presente certificación se expide en Bogotá D.C., a los '.$dia_actual.' ('.date("d").') días del mes de '.$meses[date('n')-1].' de '.date("Y").'.</div>
                                <br/><br/>
                                <div style="text-align:center"><img src="http://localhost/report_SCRD_pv/images/firma_'.$propuesta->getConvocatorias()->getEntidades()->nombre.'.png"  width="100" height="70" border="0" /><br/>
                                <b>'.$nombre_firma.'</b><br/>
                                '.$cargo_firma.'</div>
                                ';                                
                    }

                    //como persona natural
                    if($request->getPut('tp')=='AGRU')
                    {
                        //Integrantes de la Agrupacion
                        $conditions = ['id' => $propuesta->participante, 'participante_padre' => $propuesta->participante, 'tipo' => 'Integrante', 'active' => true];
                        $consulta_integrantes = Participantes::find(([
                                    'conditions' => 'id<>:id: AND participante_padre=:participante_padre: AND tipo=:tipo: AND active=:active:',
                                    'bind' => $conditions,
                                    "order" => 'representante DESC'
                        ]));

                        $i = 1;
                        $html_integrantes = "";
                        foreach ($consulta_integrantes as $integrante) {
                            $value_representante="No";
                            if($integrante->representante)
                            {
                                $value_representante="Sí";
                            }
                            
                            $html_integrantes = $html_integrantes . "<tr>";
                            $html_integrantes = $html_integrantes . "<td>" . $integrante->primer_nombre . " " . $integrante->segundo_nombre . " ". $integrante->primer_apellido . " " . $integrante->segundo_apellido . "</td>";
                            $html_integrantes = $html_integrantes . "<td>" . $integrante->getTiposdocumentos()->nombre ." ".$integrante->numero_documento. "</td>";
                            $html_integrantes = $html_integrantes . "<td>" . $integrante->rol . "</td>";
                            $html_integrantes = $html_integrantes . "</tr>";                            
                        }
                        
                        
                        $html = '
                                <br/>
                                <br/>
                                <br/>
                                <div style="text-align:center"><b>LA SUSCRITA '.mb_strtoupper($cargo_firma).' DE LA <br/>'.mb_strtoupper($propuesta->getConvocatorias()->getEntidades()->descripcion).' - '.mb_strtoupper($propuesta->getConvocatorias()->getEntidades()->nombre).'</b></div>
                                <br/>
                                <br/>
                                <div style="text-align:center"><b>CERTIFICA QUE:</b></div>
                                <br/>
                                <br/>
                                <div style="text-align: justify">Según Resolución No. 
                                '.$propuesta->numero_resolucion.' del 
                                '.date("d",strtotime($propuesta->fecha_resolucion)).' de '.$meses[date("n",strtotime($propuesta->fecha_resolucion))-1].' de '.date("Y",strtotime($propuesta->fecha_resolucion)).'
                                “'.$propuesta->nombre_resolucion.'”, 
                                la '.$tipo_participante_participante.' 
                                “'.$nombre_participante.'” 
                                fue seleccionada como una de las ganadoras de la citada convocatoria por la propuesta 
                                “'.$propuesta->nombre.'”, 
                                y se le otorgó como valor del estímulo económico la suma de 
                                '.mb_strtoupper($valor_letras).' DE PESOS ($'.number_format($propuesta->monto_asignado).') M/CTE, 
                                ejecutados en el proyecto desde 
                                el '.date("d",strtotime($propuesta->fecha_inicio_ejecucion)).' de '.$meses[date("n",strtotime($propuesta->fecha_inicio_ejecucion))-1].' del '.date("Y",strtotime($propuesta->fecha_inicio_ejecucion)).'  al '.date("d",strtotime($propuesta->fecha_fin_ejecucion)).' de '.$meses[date("n",strtotime($propuesta->fecha_fin_ejecucion))-1].' del '.date("Y",strtotime($propuesta->fecha_fin_ejecucion)).' 
                                y cumpliendo con los deberes como ganadores del 
                                '.$propuesta->getConvocatorias()->getProgramas()->nombre.'
                                </div>
                                <div style="text-align: justify">De acuerdo con la inscripción realizada por la 
                                '.$tipo_participante_participante.'  
                                en el Sistema de Convocatorias Públicas del 
                                Sector Cultura, Recreación y Deporte 
                                -SICON se certifica la siguiente participación:
                                </div>
                                <br/>
                                <table border="1">
                                    <tr>
                                        <td bgcolor="#cccccc" align="center">Nombre</td>
                                        <td bgcolor="#cccccc" align="center">No Identificación</td>                            
                                        <td bgcolor="#cccccc" align="center">Rol</td>                            
                                    </tr>
                                    '.$html_integrantes.'
                                </table>
                                <br/>
                                <div>La presente certificación se expide en Bogotá D.C., a los '.$dia_actual.' ('.date("d").') días del mes de '.$meses[date('n')-1].' de '.date("Y").'.</div>
                                <br/><br/>
                                <div style="text-align:center"><img src="http://localhost/report_SCRD_pv/images/firma_'.$propuesta->getConvocatorias()->getEntidades()->nombre.'.png"  width="100" height="70" border="0" /><br/>
                                <b>'.$nombre_firma.'</b><br/>
                                '.$cargo_firma.'</div>
                                ';                                 
                    } 

                    //como persona natural
                    if($request->getPut('tp')=='JUR')
                    {
                        $html = '
                                <br/>
                                <br/>
                                <br/>
                                <div style="text-align:center"><b>LA SUSCRITA '.mb_strtoupper($cargo_firma).' DE LA <br/>'.mb_strtoupper($propuesta->getConvocatorias()->getEntidades()->descripcion).' - '.mb_strtoupper($propuesta->getConvocatorias()->getEntidades()->nombre).'</b></div>
                                <br/>
                                <br/>
                                <div style="text-align:center"><b>CERTIFICA QUE:</b></div>
                                <br/>
                                <br/>
                                <div style="text-align: justify">Según Resolución No. 
                                '.$propuesta->numero_resolucion.' del 
                                '.date("d",strtotime($propuesta->fecha_resolucion)).' de '.$meses[date("n",strtotime($propuesta->fecha_resolucion))-1].' de '.date("Y",strtotime($propuesta->fecha_resolucion)).'
                                “'.$propuesta->nombre_resolucion.'”, 
                                el señor(a)
                                '.$nombre_participante.' identificado(a) con 
                                '.$tipo_documento_participante.' No. '.$documento_participante.',                                 
                                fue designado como uno de los jurados de la citada convocatoria y se le otorgó como valor del estímulo económico la suma de 
                                '.mb_strtoupper($valor_letras).' DE PESOS ($'.number_format($propuesta->monto_asignado).') M/CTE.
                                </div>                                
                                <br/>
                                <div>La presente certificación se expide en Bogotá D.C., a los '.$dia_actual.' ('.date("d").') días del mes de '.$meses[date('n')-1].' de '.date("Y").'.</div>
                                <br/><br/>
                                <div style="text-align:center"><img src="http://localhost/report_SCRD_pv/images/firma_'.$propuesta->getConvocatorias()->getEntidades()->nombre.'.png"  width="100" height="70" border="0" /><br/>
                                <b>'.$nombre_firma.'</b><br/>
                                '.$cargo_firma.'</div>
                                ';                                      
                    }
                }
                else
                {
                    //Valido para genere solo las propuestas que pasaron el filtro de inscritos
                    //y el filtro para jurados de Registrado
                    $participante=false;                
                    if($propuesta->estado!=7 && $propuesta->estado!=20 && $propuesta->estado!=9)
                    {
                        $participante=true;
                    }
                    
                    if($participante)
                    {
                        
                        //Si la convocatoria seleccionada es categoria, debo invertir los nombres la convocatoria con la categoria
                        $nombre_convocatoria = $propuesta->getConvocatorias()->nombre;
                        if ($propuesta->getConvocatorias()->convocatoria_padre_categoria > 0) {
                            $nombre_convocatoria = $propuesta->getConvocatorias()->getConvocatorias()->nombre;                        
                        }


                        //como persona natural
                        if($request->getPut('tp')=='PN')
                        {
                            $html = '
                                    <br/>
                                    <br/>
                                    <br/>
                                    <div style="text-align:center"><b>LA SUSCRITA '.mb_strtoupper($cargo_firma).' DE LA <br/>'.mb_strtoupper($propuesta->getConvocatorias()->getEntidades()->descripcion).' - '.mb_strtoupper($propuesta->getConvocatorias()->getEntidades()->nombre).'</b></div>
                                    <br/>
                                    <br/>
                                    <div style="text-align:center"><b>CERTIFICA QUE:</b></div>
                                    <br/>
                                    <br/>
                                    <div style="text-align: justify">Una vez revisado el Sistema de Convocatorias Públicas del Sector Cultura, Recreación y Deporte -SICON, se evidencia la participación de
                                    la '.$tipo_participante_participante.'                                 
                                    en la convocatoria 
                                    “'.$nombre_convocatoria.'” 
                                    con la propuesta
                                    “'.$propuesta->nombre.'” (Código '.$propuesta->codigo.').
                                    </div>
                                    <div style="text-align: justify">De acuerdo con la inscripción realizada por la 
                                    '.$tipo_participante_participante.'  
                                    en el Sistema de Convocatorias Públicas del 
                                    Sector Cultura, Recreación y Deporte 
                                    -SICON se certifica la siguiente participación:
                                    </div>
                                    <br/>
                                    <table border="1">
                                        <tr>
                                            <td bgcolor="#cccccc" align="center">Nombre</td>
                                            <td bgcolor="#cccccc" align="center">No Identificación</td>                            
                                            <td bgcolor="#cccccc" align="center">Tipo de Participación</td>                            
                                        </tr>
                                        <tr>
                                            <td>'.$nombre_participante.'</td>
                                            <td>'.$tipo_documento_participante.' No. '.$documento_participante.'</td>                            
                                            <td>Persona Natural</td>                            
                                        </tr>
                                    </table>                                
                                    <br/>
                                    <div>La presente certificación se expide en Bogotá D.C., a los '.$dia_actual.' ('.date("d").') días del mes de '.$meses[date('n')-1].' de '.date("Y").'.</div>
                                    <br/><br/>
                                    <div style="text-align:center"><img src="http://localhost/report_SCRD_pv/images/firma_'.$propuesta->getConvocatorias()->getEntidades()->nombre.'.png"  width="100" height="70" border="0" /><br/>
                                    <b>'.$nombre_firma.'</b><br/>
                                    '.$cargo_firma.'</div>
                                    ';                                                                                  
                        }

                        //como persona natural
                        if($request->getPut('tp')=='PJ')
                        {

                            //Integrantes de la PJ
                            $conditions = ['id' => $propuesta->participante, 'participante_padre' => $propuesta->participante, 'tipo' => 'Junta', 'active' => true];
                            $consulta_integrantes = Participantes::find(([
                                        'conditions' => 'id<>:id: AND participante_padre=:participante_padre: AND tipo=:tipo: AND active=:active:',
                                        'bind' => $conditions,
                                        "order" => 'representante DESC'
                            ]));

                            $i = 1;
                            $html_integrantes = "";
                            foreach ($consulta_integrantes as $integrante) {
                                $value_representante="No";
                                if($integrante->representante)
                                {
                                    $value_representante="Sí";
                                }

                                $html_integrantes = $html_integrantes . "<tr>";
                                $html_integrantes = $html_integrantes . "<td>" . $integrante->primer_nombre . " " . $integrante->segundo_nombre . " ". $integrante->primer_apellido . " " . $integrante->segundo_apellido . "</td>";
                                $html_integrantes = $html_integrantes . "<td>" . $integrante->getTiposdocumentos()->nombre ." ".$integrante->numero_documento. "</td>";
                                $html_integrantes = $html_integrantes . "<td>JUNTA DIRECTIVA</td>";
                                $html_integrantes = $html_integrantes . "</tr>";                            
                            }


                            $html = '
                                    <br/>
                                    <br/>
                                    <br/>
                                    <div style="text-align:center"><b>LA SUSCRITA '.mb_strtoupper($cargo_firma).' DE LA <br/>'.mb_strtoupper($propuesta->getConvocatorias()->getEntidades()->descripcion).' - '.mb_strtoupper($propuesta->getConvocatorias()->getEntidades()->nombre).'</b></div>
                                    <br/>
                                    <br/>
                                    <div style="text-align:center"><b>CERTIFICA QUE:</b></div>
                                    <br/>
                                    <br/>
                                    <div style="text-align: justify">Una vez revisado el Sistema de Convocatorias Públicas del Sector Cultura, Recreación y Deporte -SICON, se evidencia la participación de
                                    la '.$tipo_participante_participante.'                                 
                                    en la convocatoria 
                                    “'.$nombre_convocatoria.'” 
                                    con la propuesta
                                    “'.$propuesta->nombre.'” (Código '.$propuesta->codigo.').
                                    </div>
                                    <div style="text-align: justify">De acuerdo con la inscripción realizada por la 
                                    '.$tipo_participante_participante.'  
                                    en el Sistema de Convocatorias Públicas del 
                                    Sector Cultura, Recreación y Deporte 
                                    -SICON se certifica la siguiente participación:
                                    </div>
                                    <br/>
                                    <table border="1">
                                        <tr>
                                            <td bgcolor="#cccccc" align="center">Nombre</td>
                                            <td bgcolor="#cccccc" align="center">No Identificación</td>                            
                                            <td bgcolor="#cccccc" align="center">Tipo de Participación</td>                            
                                        </tr>
                                        '.$html_integrantes.'
                                    </table>                                
                                    <br/>
                                    <div>La presente certificación se expide en Bogotá D.C., a los '.$dia_actual.' ('.date("d").') días del mes de '.$meses[date('n')-1].' de '.date("Y").'.</div>
                                    <br/><br/>
                                    <div style="text-align:center"><img src="http://localhost/report_SCRD_pv/images/firma_'.$propuesta->getConvocatorias()->getEntidades()->nombre.'.png"  width="100" height="70" border="0" /><br/>
                                    <b>'.$nombre_firma.'</b><br/>
                                    '.$cargo_firma.'</div>
                                    ';                                  
                        }

                        //como persona natural
                        if($request->getPut('tp')=='AGRU')
                        {
                            //Integrantes de la Agrupacion
                            $conditions = ['id' => $propuesta->participante, 'participante_padre' => $propuesta->participante, 'tipo' => 'Integrante', 'active' => true];
                            $consulta_integrantes = Participantes::find(([
                                        'conditions' => 'id<>:id: AND participante_padre=:participante_padre: AND tipo=:tipo: AND active=:active:',
                                        'bind' => $conditions,
                                        "order" => 'representante DESC'
                            ]));

                            $i = 1;
                            $html_integrantes = "";
                            foreach ($consulta_integrantes as $integrante) {
                                $value_representante="No";
                                if($integrante->representante)
                                {
                                    $value_representante="Sí";
                                }

                                $html_integrantes = $html_integrantes . "<tr>";
                                $html_integrantes = $html_integrantes . "<td>" . $integrante->primer_nombre . " " . $integrante->segundo_nombre . " ". $integrante->primer_apellido . " " . $integrante->segundo_apellido . "</td>";
                                $html_integrantes = $html_integrantes . "<td>" . $integrante->getTiposdocumentos()->nombre ." ".$integrante->numero_documento. "</td>";
                                $html_integrantes = $html_integrantes . "<td>INTEGRANTE</td>";
                                $html_integrantes = $html_integrantes . "</tr>";                            
                            }


                            $html = '
                                    <br/>
                                    <br/>
                                    <br/>
                                    <div style="text-align:center"><b>LA SUSCRITA '.mb_strtoupper($cargo_firma).' DE LA <br/>'.mb_strtoupper($propuesta->getConvocatorias()->getEntidades()->descripcion).' - '.mb_strtoupper($propuesta->getConvocatorias()->getEntidades()->nombre).'</b></div>
                                    <br/>
                                    <br/>
                                    <div style="text-align:center"><b>CERTIFICA QUE:</b></div>
                                    <br/>
                                    <br/>
                                    <div style="text-align: justify">Una vez revisado el Sistema de Convocatorias Públicas del Sector Cultura, Recreación y Deporte -SICON, se evidencia la participación de
                                    la '.$tipo_participante_participante.'                                 
                                    en la convocatoria 
                                    “'.$nombre_convocatoria.'” 
                                    con la propuesta
                                    “'.$propuesta->nombre.'” (Código '.$propuesta->codigo.').
                                    </div>
                                    <div style="text-align: justify">De acuerdo con la inscripción realizada por la 
                                    '.$tipo_participante_participante.'  
                                    en el Sistema de Convocatorias Públicas del 
                                    Sector Cultura, Recreación y Deporte 
                                    -SICON se certifica la siguiente participación:
                                    </div>
                                    <br/>
                                    <table border="1">
                                        <tr>
                                            <td bgcolor="#cccccc" align="center">Nombre</td>
                                            <td bgcolor="#cccccc" align="center">No Identificación</td>                            
                                            <td bgcolor="#cccccc" align="center">Tipo de Participación</td>                            
                                        </tr>
                                        '.$html_integrantes.'
                                    </table>                                
                                    <br/>
                                    <div>La presente certificación se expide en Bogotá D.C., a los '.$dia_actual.' ('.date("d").') días del mes de '.$meses[date('n')-1].' de '.date("Y").'.</div>
                                    <br/><br/>
                                    <div style="text-align:center"><img src="http://localhost/report_SCRD_pv/images/firma_'.$propuesta->getConvocatorias()->getEntidades()->nombre.'.png"  width="100" height="70" border="0" /><br/>
                                    <b>'.$nombre_firma.'</b><br/>
                                    '.$cargo_firma.'</div>
                                    ';                                 
                        } 

                        //como persona natural
                        if($request->getPut('tp')=='JUR')
                        {
                            $html = '
                                    <br/>
                                    <br/>
                                    <br/>
                                    <div style="text-align:center"><b>LA SUSCRITA '.mb_strtoupper($cargo_firma).' DE LA <br/>'.mb_strtoupper($propuesta->getConvocatorias()->getEntidades()->descripcion).' - '.mb_strtoupper($propuesta->getConvocatorias()->getEntidades()->nombre).'</b></div>
                                    <br/>
                                    <br/>
                                    <div style="text-align:center"><b>CERTIFICA QUE:</b></div>
                                    <br/>
                                    <br/>
                                    <div style="text-align: justify">Una vez revisado el Sistema de Convocatorias Públicas del Sector Cultura, Recreación y Deporte -SICON, se evidencia la participación de
                                    '.$nombre_participante.' identificado(a) con 
                                    '.$tipo_documento_participante.' No. '.$documento_participante.' 
                                     en el banco de Jurados del Programa Distrital de Estímulos.  
                                    </div>                                
                                    <br/>
                                    <div>La presente certificación se expide en Bogotá D.C., a los '.$dia_actual.' ('.date("d").') días del mes de '.$meses[date('n')-1].' de '.date("Y").'.</div>
                                    <br/><br/>
                                    <div style="text-align:center"><img src="http://localhost/report_SCRD_pv/images/firma_'.$propuesta->getConvocatorias()->getEntidades()->nombre.'.png"  width="100" height="70" border="0" /><br/>
                                    <b>'.$nombre_firma.'</b><br/>
                                    '.$cargo_firma.'</div>
                                    ';                                      
                        }
                    }
                
                
                }
                
                
                

                $logger->info('"token":"{token}","user":"{user}","message":"Se genero el reporte de certificacion de la propuesta (' . $request->getPut('id') . ')', ['user' => $user_current["username"], 'token' => $request->getPut('token')]);
                $logger->close();
                echo $html;
            } else {
                //Registro la accion en el log de convocatorias           
                $logger->error('"token":"{token}","user":"{user}","message":"La propuesta (' . $request->getPut('id') . ') no existe en el metodo certificacion', ['user' => "", 'token' => $request->getPut('token')]);
                $logger->close();
                echo "error_propuesta";
            }
        } else {
            //Registro la accion en el log de convocatorias           
            $logger->error('"token":"{token}","user":"{user}","message":"Token caduco en el metodo certificacion al generar el reporte de la propuesta (' . $request->getPut('id') . ')', ['user' => "", 'token' => $request->getPut('token')]);
            $logger->close();
            echo "error_token";
        }
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias           
        $logger->error('"token":"{token}","user":"{user}","message":"Error metodo certificacion al generar el reporte de la propuesta (' . $request->getPut('id') . ')' . $ex->getMessage() . '"', ['user' => "", 'token' => $request->getPut('token')]);
        $logger->close();
        echo "error_metodo";
    }
});

$app->get('/validar_certificado/{cod}', function ($cod) use ($app, $config, $logger) {

    //Instancio los objetos que se van a manejar
    $request = new Request();    
    try {
        $cod = str_replace('ZXXY', '-', $_GET["id"]);
        $propuesta = Propuestas::findFirst("codigo='".$cod."'");

        if (isset($propuesta->id)) {
            echo "SICON reporta que es un certificado Valido";
        } else {
            echo "SICON reporta que no es un certificado confiable";
        }        
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias           
        $logger->error('"token":"{token}","user":"{user}","message":"Error metodo certificacion al generar el reporte de la propuesta (' . $request->getPut('id') . ')' . $ex->getMessage() . '"', ['user' => "", 'token' => $request->getPut('token')]);
        $logger->close();
        echo "error_metodo";
    }
});

$app->post('/reporte_propuesta_inscrita', function () use ($app, $config, $logger) {

//Instancio los objetos que se van a manejar
    $request = new Request();
    $tokens = new Tokens();

    try {

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPut('token'));

        //Registro la accion en el log de convocatorias
        $logger->info('"token":"{token}","user":"{user}","message":"Ingresa al metodo reporte_propuesta_inscrita para generar reporte de inscripcion de la propuesta (' . $request->getPut('id') . ')"', ['user' => '', 'token' => $request->getPut('token')]);

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

            $propuesta = Propuestas::findFirst($request->getPut('id'));

            if (isset($propuesta->id)) {
                
                //Valido para que genere el reporte solo al momento de 
                //clic en generar reporte al inscribir la propuesta por primera vez
                $estado = $propuesta->estado;
                $titulo_reporte="CERTIFICADO DE INSCRIPCIÓN";
                $generar = $request->getPut('vi');
                $parrafo_1="Su inscripción ha sido realizada correctamente. Recuerde que con la inscripción, su propuesta pasa al período de revisión de los requisitos formales del concurso, pero deberá estar atento en caso de que le sea solicitada la subsanación de alguno de los documentos.";
                if($generar==1)
                {
                    $estado=8;
                    $titulo_reporte="CERTIFICADO DE PRE-INSCRIPCIÓN";
                    $parrafo_1="Su inscripción no ha sido confirmada. Recuerde que con la inscripción, su propuesta pasa al período de revisión de los requisitos formales del concurso, pero deberá estar atento en caso de que le sea solicitada la subsanación de alguno de los documentos.";
                }
                
                if ($estado <> 7 && $estado <> 20) {
                    $array_administrativos = array();
                    $array_tecnicos = array();
                    foreach ($propuesta->Propuestasdocumentos as $propuestadocumento) {
                        if ($propuestadocumento->getConvocatoriasdocumentos()->getRequisitos()->tipo_requisito == "Administrativos" AND $propuestadocumento->cargue_subsanacion == false AND $propuestadocumento->active == true) {
                            $array_administrativos[$propuestadocumento->id]["requisito"] = $propuestadocumento->getConvocatoriasdocumentos()->getRequisitos()->nombre;
                            $array_administrativos[$propuestadocumento->id]["nombre"] = $propuestadocumento->nombre;
                        }

                        if ($propuestadocumento->getConvocatoriasdocumentos()->getRequisitos()->tipo_requisito == "Tecnicos" AND $propuestadocumento->active == true) {
                            $array_tecnicos[$propuestadocumento->id]["requisito"] = $propuestadocumento->getConvocatoriasdocumentos()->getRequisitos()->nombre;
                            $array_tecnicos[$propuestadocumento->id]["nombre"] = $propuestadocumento->nombre;
                        }
                    }

                    $array_administrativos_link = array();
                    $array_tecnicos_link = array();
                    foreach ($propuesta->Propuestaslinks as $propuestalink) {
                        if ($propuestalink->getConvocatoriasdocumentos()->getRequisitos()->tipo_requisito == "Administrativos" AND $propuestalink->cargue_subsanacion == false  AND $propuestalink->active == true) {
                            $array_administrativos_link[$propuestalink->id]["requisito"] = $propuestalink->getConvocatoriasdocumentos()->getRequisitos()->nombre;
                            $array_administrativos_link[$propuestalink->id]["link"] = $propuestalink->link;
                        }

                        if ($propuestalink->getConvocatoriasdocumentos()->getRequisitos()->tipo_requisito == "Tecnicos" AND $propuestalink->active == true) {
                            $array_tecnicos_link[$propuestalink->id]["requisito"] = $propuestalink->getConvocatoriasdocumentos()->getRequisitos()->nombre;
                            $array_tecnicos_link[$propuestalink->id]["link"] = $propuestalink->link;
                        }
                    }

                    $html_administrativos = "";
                    $i = 1;
                    foreach ($array_administrativos as $key => $val) {
                        $html_administrativos = $html_administrativos . "<tr>";
                        $html_administrativos = $html_administrativos . "<td>" . $i . "</td>";
                        $html_administrativos = $html_administrativos . "<td>" . $val["requisito"] . "</td>";
                        $html_administrativos = $html_administrativos . "<td>" . $val["nombre"] . "</td>";
                        $html_administrativos = $html_administrativos . "</tr>";
                        $i++;
                    }

                    $html_administrativos_link = "";
                    $i = 1;
                    foreach ($array_administrativos_link as $key => $val) {
                        $html_administrativos_link = $html_administrativos_link . "<tr>";
                        $html_administrativos_link = $html_administrativos_link . "<td>" . $i . "</td>";
                        $html_administrativos_link = $html_administrativos_link . "<td>" . $val["requisito"] . "</td>";
                        $html_administrativos_link = $html_administrativos_link . "<td>" . $val["link"] . "</td>";
                        $html_administrativos_link = $html_administrativos_link . "</tr>";
                        $i++;
                    }

                    $html_tecnicos = "";
                    $i = 1;
                    foreach ($array_tecnicos as $key => $val) {
                        $html_tecnicos = $html_tecnicos . "<tr>";
                        $html_tecnicos = $html_tecnicos . "<td>" . $i . "</td>";
                        $html_tecnicos = $html_tecnicos . "<td>" . $val["requisito"] . "</td>";
                        $html_tecnicos = $html_tecnicos . "<td>" . $val["nombre"] . "</td>";
                        $html_tecnicos = $html_tecnicos . "</tr>";
                        $i++;
                    }

                    $html_tecnicos_link = "";
                    $i = 1;
                    foreach ($array_tecnicos_link as $key => $val) {
                        $html_tecnicos_link = $html_tecnicos_link . "<tr>";
                        $html_tecnicos_link = $html_tecnicos_link . "<td>" . $i . "</td>";
                        $html_tecnicos_link = $html_tecnicos_link . "<td>" . $val["requisito"] . "</td>";
                        $html_tecnicos_link = $html_tecnicos_link . "<td>" . $val["link"] . "</td>";
                        $html_tecnicos_link = $html_tecnicos_link . "</tr>";
                        $i++;
                    }

                    $bogota = ($propuesta->bogota) ? "Si" : "No";

                    //Si la convocatoria seleccionada es categoria, debo invertir los nombres la convocatoria con la categoria
                    $nombre_convocatoria = $propuesta->getConvocatorias()->nombre;
                    $nombre_categoria = "";
                    if ($propuesta->getConvocatorias()->convocatoria_padre_categoria > 0) {
                        $nombre_convocatoria = $propuesta->getConvocatorias()->getConvocatorias()->nombre;
                        $nombre_categoria = $propuesta->getConvocatorias()->nombre;
                    }

                    $participante = $propuesta->getParticipantes()->primer_nombre . " " . $propuesta->getParticipantes()->segundo_nombre . " " . $propuesta->getParticipantes()->primer_apellido . " " . $propuesta->getParticipantes()->segundo_apellido;

                    //Creo la tabla deacuerdo al tipo de participante
                    //Participante natural
                    if ($propuesta->getParticipantes()->getUsuariosperfiles()->getPerfiles()->id == 6) {
                        $tabla_participante = '<table>
    <tr>
        <td>Tipo de documento de identificación</td>
        <td>' . $propuesta->getParticipantes()->getTiposdocumentos()->descripcion . '</td>    
        <td>Número de documento de identificación</td>
        <td>' . $propuesta->getParticipantes()->numero_documento . '</td>
    </tr>    
    <tr>
        <td>Primer nombre</td>
        <td>' . $propuesta->getParticipantes()->primer_nombre . '</td>
        <td>Segundo nombre</td>
        <td>' . $propuesta->getParticipantes()->segundo_nombre . '</td>
    </tr>    
    <tr>
        <td>Primer apellido</td>
        <td>' . $propuesta->getParticipantes()->primer_apellido . '</td>
        <td>Segundo apellido</td>
        <td>' . $propuesta->getParticipantes()->segundo_apellido . '</td>
    </tr>    
    <tr>
        <td>Sexo</td>
        <td>' . $propuesta->getParticipantes()->getSexos()->nombre . '</td>
        <td>Orientación Sexual</td>
        <td>' . $propuesta->getParticipantes()->getOrientacionessexuales()->nombre . '</td>
    </tr>    
    <tr>
        <td>Identidad de género</td>
        <td>' . $propuesta->getParticipantes()->getIdentidadesgeneros()->nombre . '</td>
        <td>Grupo étnico</td>
        <td>' . $propuesta->getParticipantes()->getGruposetnicos()->nombre . '</td>
    </tr>    
    <tr>
        <td>Fecha de nacimiento</td>
        <td>' . $propuesta->getParticipantes()->fecha_nacimiento . '</td>
        <td>Ciudad de nacimiento</td>
        <td>' . $propuesta->getParticipantes()->getCiudadesnacimiento()->nombre . '</td>
    </tr>    
    <tr>
        <td>Ciudad de residencia</td>
        <td>' . $propuesta->getParticipantes()->getCiudadesresidencia()->nombre . '</td>
        <td>Barrio residencia</td>
        <td>' . $propuesta->getParticipantes()->getBarriosresidencia()->nombre . '</td>
    </tr>    
    <tr>
        <td>Dirección de residencia</td>
        <td>' . $propuesta->getParticipantes()->direccion_residencia . '</td>
        <td>Dirección correspondencia</td>
        <td>' . $propuesta->getParticipantes()->direccion_correspondencia . '</td>
    </tr>    
    <tr>
        <td>Estrato</td>
        <td>' . $propuesta->getParticipantes()->estrato . '</td>
        <td>Teléfono fijo</td>
        <td>' . $propuesta->getParticipantes()->numero_telefono . '</td>
    </tr>    
    <tr>
        <td>Número de celular personal</td>
        <td>' . $propuesta->getParticipantes()->numero_celular . '</td>
        <td>Correo electrónico</td>
        <td>' . $propuesta->getParticipantes()->correo_electronico . '</td>
    </tr>    
    <tr>
        <td>Redes sociales</td>
        <td>' . $propuesta->getParticipantes()->facebook . '</td>
        <td>Página web, vínculo o blog</td>
        <td>' . $propuesta->getParticipantes()->links . '</td>
    </tr>           
</table>';
                    }
                    //Participante juridico
                    if ($propuesta->getParticipantes()->getUsuariosperfiles()->getPerfiles()->id == 7) {
                        $conditions = ['id' => $propuesta->participante, 'participante_padre' => $propuesta->participante, 'tipo' => 'Junta', 'active' => true];

//Se crea todo el array de las rondas de evaluacion
                        $consulta_integrantes = Participantes::find(([
                                    'conditions' => 'id<>:id: AND participante_padre=:participante_padre: AND tipo=:tipo: AND active=:active:',
                                    'bind' => $conditions,
                                    "order" => 'representante DESC'
                        ]));

                        $i = 1;
                        $html_integrantes = "";
                        foreach ($consulta_integrantes as $integrante) {
                            $value_representante="No";
                            if($integrante->representante)
                            {
                                $value_representante="Sí";
                            }
                            
                            $html_integrantes = $html_integrantes . "<tr>";
                            $html_integrantes = $html_integrantes . "<td>" . $i . "</td>";
                            $html_integrantes = $html_integrantes . "<td>" . $value_representante . "</td>";
                            $html_integrantes = $html_integrantes . "<td>" . $integrante->getTiposdocumentos()->descripcion . "</td>";
                            $html_integrantes = $html_integrantes . "<td>" . $integrante->numero_documento . "</td>";
                            $html_integrantes = $html_integrantes . "<td>" . $integrante->primer_nombre . " " . $integrante->segundo_nombre . "</td>";
                            $html_integrantes = $html_integrantes . "<td>" . $integrante->primer_apellido . " " . $integrante->segundo_apellido . "</td>";
                            $html_integrantes = $html_integrantes . "<td>" . $integrante->getCiudadesresidencia()->nombre . "</td>";                            
                            $html_integrantes = $html_integrantes . "<td>" . $integrante->rol . "</td>";
                            $html_integrantes = $html_integrantes . "</tr>";
                            $i++;
                        }


                        $cuenta_sede = ($propuesta->getParticipantes()->cuenta_sede) ? 'Sí' : 'No';
                        $tabla_participante = '<table>
    <tr>
        <td>Tipo de documento de identificación</td>
        <td>' . $propuesta->getParticipantes()->getTiposdocumentos()->descripcion . '</td>    
        <td>Número de Nit</td>
        <td>' . $propuesta->getParticipantes()->numero_documento . '</td>
    </tr>    
    <tr>
        <td>DV</td>
        <td>' . $propuesta->getParticipantes()->dv . '</td>
        <td>Razón Social</td>
        <td>' . $propuesta->getParticipantes()->primer_nombre . '</td>
    </tr>       
    <tr>
        <td>Municipio</td>
        <td>' . $propuesta->getParticipantes()->getCiudadesresidencia()->nombre . '</td>
        <td>Barrio</td>
        <td>' . $propuesta->getParticipantes()->getBarriosresidencia()->nombre . '</td>
    </tr>    
    <tr>
        <td>Estrato</td>
        <td>' . $propuesta->getParticipantes()->estrato . '</td>
        <td>Dirección</td>
        <td>' . $propuesta->getParticipantes()->direccion_residencia . '</td>        
    </tr>    
    <tr>
        <td>Teléfono fijo</td>
        <td>' . $propuesta->getParticipantes()->numero_telefono . '</td>
        <td>Número de celular</td>
        <td>' . $propuesta->getParticipantes()->numero_celular . '</td>
    </tr>    
    <tr>
        <td>Objeto Social</td>
        <td>' . $propuesta->getParticipantes()->objeto_social . '</td>
        <td>Fecha de Constitución</td>
        <td>' . $propuesta->getParticipantes()->fecha_nacimiento . '</td>
    </tr>    
    <tr>
        <td>Correo electrónico</td>
        <td>' . $propuesta->getParticipantes()->correo_electronico . '</td>
        <td>¿Cuenta con sede?</td>
        <td>' . $cuenta_sede . '</td>
    </tr>    
    <tr>
        <td>Tipo de sede</td>
        <td>' . $propuesta->getParticipantes()->tipo_sede . '</td>
        <td>Redes sociales</td>
        <td>' . $propuesta->getParticipantes()->facebook . '</td>        
    </tr> 
    <tr>
        <td>Página web, vínculo o blog</td>
        <td>' . $propuesta->getParticipantes()->links . '</td>
    </tr> 
</table>
<h3>Junta directiva</h3>
<table>    
    <tr>
        <td align="center" bgcolor="#BDBDBD">N°</td>
        <td align="center" bgcolor="#BDBDBD">¿Representante?</td>    
        <td align="center" bgcolor="#BDBDBD">Tipo de documento</td>    
        <td align="center" bgcolor="#BDBDBD">Número de documento de identificación</td>    
        <td align="center" bgcolor="#BDBDBD">Nombres</td>        
        <td align="center" bgcolor="#BDBDBD">Apellidos</td>        
        <td align="center" bgcolor="#BDBDBD">Ciudad de residencia</td>        
        <td align="center" bgcolor="#BDBDBD">Rol que desempeña o ejecuta en la propuesta</td>                
    </tr> 
    ' . $html_integrantes . '
</table>
';
                    }
                    //Participante agrupacion
                    if ($propuesta->getParticipantes()->getUsuariosperfiles()->getPerfiles()->id == 8) {

                        $conditions = ['id' => $propuesta->participante, 'participante_padre' => $propuesta->participante, 'tipo' => 'Integrante', 'active' => true];

//Se crea todo el array de las rondas de evaluacion
                        $consulta_integrantes = Participantes::find(([
                                    'conditions' => 'id<>:id: AND participante_padre=:participante_padre: AND tipo=:tipo: AND active=:active:',
                                    'bind' => $conditions,
                                    "order" => 'representante DESC'
                        ]));

                        $i = 1;
                        $html_integrantes = "";
                        foreach ($consulta_integrantes as $integrante) {
                            $value_representante="No";
                            if($integrante->representante)
                            {
                                $value_representante="Sí";
                            }
                            
                            $html_integrantes = $html_integrantes . "<tr>";
                            $html_integrantes = $html_integrantes . "<td>" . $i . "</td>";
                            $html_integrantes = $html_integrantes . "<td>" . $value_representante . "</td>";
                            $html_integrantes = $html_integrantes . "<td>" . $integrante->getTiposdocumentos()->descripcion . "</td>";
                            $html_integrantes = $html_integrantes . "<td>" . $integrante->numero_documento . "</td>";
                            $html_integrantes = $html_integrantes . "<td>" . $integrante->primer_nombre . " " . $integrante->segundo_nombre . "</td>";
                            $html_integrantes = $html_integrantes . "<td>" . $integrante->primer_apellido . " " . $integrante->segundo_apellido . "</td>";
                            $html_integrantes = $html_integrantes . "<td>" . $integrante->getCiudadesresidencia()->nombre . "</td>";                            
                            $html_integrantes = $html_integrantes . "<td>" . $integrante->rol . "</td>";
                            $html_integrantes = $html_integrantes . "</tr>";
                            $i++;
                        }


                        $tabla_participante = '<table>
    <tr>
        <td>Nombre de la agrupación</td>
        <td>' . $propuesta->getParticipantes()->primer_nombre . '</td>
        <td>Correo electrónico de la entidad</td>
        <td>' . $propuesta->getParticipantes()->correo_electronico . '</td>
    </tr>    
    <tr>        
        <td>Redes sociales</td>
        <td>' . $propuesta->getParticipantes()->facebook . '</td>            
        <td>Página web, vínculo o blog</td>
        <td>' . $propuesta->getParticipantes()->links . '</td>
    </tr> 
</table>
<h3>Integrantes</h3>
<table>    
    <tr>
        <td align="center" bgcolor="#BDBDBD">N°</td>
        <td align="center" bgcolor="#BDBDBD">¿Representante?</td>    
        <td align="center" bgcolor="#BDBDBD">Tipo de documento</td>    
        <td align="center" bgcolor="#BDBDBD">Número de documento de identificación</td>    
        <td align="center" bgcolor="#BDBDBD">Nombres</td>        
        <td align="center" bgcolor="#BDBDBD">Apellidos</td>        
        <td align="center" bgcolor="#BDBDBD">Ciudad de residencia</td> 
        <td align="center" bgcolor="#BDBDBD">Rol que desempeña o ejecuta en la propuesta</td>                
    </tr> 
    ' . $html_integrantes . '
</table>
';
                    }


                    $html = '<!-- EXAMPLE OF CSS STYLE -->
<style>
        table {
		font-size: 10pt;	
	}
        
	td {
		border: 1px solid #E3E3E3;	
                background-color: #ffffee;
	}
</style>
<h2  style="text-align:center;">'.$titulo_reporte.'</h2>
<h3>Información de la propuesta</h3>        
<p>'.$parrafo_1.'</p>
<table>
    <tr>
        <td colspan="2"><b>Código</b></td>
        <td colspan="2"><b>' . $propuesta->codigo . '</b></td>            
    </tr>    
    <tr>
        <td>Nombre de la convocatoria</td>
        <td>' . $nombre_convocatoria . '</td>    
        <td>Categoría de la convocatoria</td>
        <td>' . $nombre_categoria . '</td>
    </tr>    
    <tr>
        <td>Nombre del participante</td>
        <td>' . $participante . '</td>
        <td>Tipo de participante</td>
        <td>' . $propuesta->getParticipantes()->getUsuariosperfiles()->getPerfiles()->nombre . '</td>
    </tr>    
    <tr>
        <td><b>Estado</b></td>
        <td><b>' . $propuesta->getEstados()->nombre . '</b></td>
        <td>Nombre de la propuesta</td>
        <td>' . $propuesta->nombre . '</td>
    </tr>    
    <tr>
        <td>¿Su propuesta se desarrolla en Bogotá D.C.?</td>
        <td>' . $bogota . '</td>
        <td>Localidad</td>
        <td>' . $propuesta->getLocalidades()->nombre . '</td>
    </tr>    
    <tr>
        <td>Upz</td>
        <td>' . $propuesta->getUpzs()->nombre . '</td>
        <td>Barrio</td>
        <td>' . $propuesta->getBarrios()->nombre . '</td>
    </tr>    
</table>
<h3>Información del participante</h3>
' . $tabla_participante . '
<h3>Documentación administrativa</h3>
<table>    
    <tr>
        <td align="center" bgcolor="#BDBDBD">N°</td>
        <td align="center" bgcolor="#BDBDBD">Requisito</td>    
        <td align="center" bgcolor="#BDBDBD">Nombre del archivo</td>        
    </tr> 
    ' . $html_administrativos . '
</table>
<br/><br/>
<table>    
    <tr>
        <td align="center" bgcolor="#BDBDBD">N°</td>
        <td align="center" bgcolor="#BDBDBD">Requisito</td>    
        <td align="center" bgcolor="#BDBDBD">Link</td>        
    </tr>       
    ' . $html_administrativos_link . '
</table>
<h3>Documentación técnica</h3>
<table>    
    <tr>
        <td align="center" bgcolor="#BDBDBD">N°</td>
        <td align="center" bgcolor="#BDBDBD">Requisito</td>    
        <td align="center" bgcolor="#BDBDBD">Nombre del archivo</td>        
    </tr>     
    ' . $html_tecnicos . '
</table>
<br/><br/>
<table>    
    <tr>
        <td align="center" bgcolor="#BDBDBD">N°</td>
        <td align="center" bgcolor="#BDBDBD">Requisito</td>    
        <td align="center" bgcolor="#BDBDBD">Link</td>        
    </tr> 
    ' . $html_tecnicos_link . '
</table>
';
                    $logger->info('"token":"{token}","user":"{user}","message":"Se genero el reporte de inscripcion de la propuesta (' . $request->getPut('id') . ')', ['user' => "", 'token' => $request->getPut('token')]);
                    $logger->close();

                    echo $html;
                } else {
                    $logger->info('"token":"{token}","user":"{user}","message":"Se genero el reporte de inscripcion de la propuesta (' . $request->getPut('id') . ')', ['user' => "", 'token' => $request->getPut('token')]);
                    $logger->close();

                    echo "<b>No es posible generar el reporte, debido a que su propuesta no esta en estado inscrita.</br>";
                    exit;
                }
            } else {
                //Registro la accion en el log de convocatorias           
                $logger->error('"token":"{token}","user":"{user}","message":"La propuesta (' . $request->getPut('id') . ') no existe en el metodo reporte_propuesta_inscrita', ['user' => "", 'token' => $request->getPut('token')]);
                $logger->close();
                echo "error_propuesta";
            }
        } else {
            //Registro la accion en el log de convocatorias           
            $logger->error('"token":"{token}","user":"{user}","message":"Token caduco en el metodo reporte_propuesta_inscrita al generar el reporte de la propuesta (' . $request->getPut('id') . ')', ['user' => "", 'token' => $request->getPut('token')]);
            $logger->close();
            echo "error_token";
        }
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias           
        $logger->error('"token":"{token}","user":"{user}","message":"Error metodo reporte_propuesta_inscrita al generar el reporte de la propuesta (' . $request->getPut('id') . ')' . $ex->getMessage() . '"', ['user' => "", 'token' => $request->getPut('token')]);
        $logger->close();
        echo "error_metodo";
    }
});

$app->post('/reporte_propuesta_inscrita_pdac', function () use ($app, $config, $logger) {

//Instancio los objetos que se van a manejar
    $request = new Request();
    $tokens = new Tokens();

    try {

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPut('token'));

        //Registro la accion en el log de convocatorias
        $logger->info('"token":"{token}","user":"{user}","message":"Ingresa al metodo reporte_propuesta_inscrita para generar reporte de inscripcion de la propuesta (' . $request->getPut('id') . ')"', ['user' => '', 'token' => $request->getPut('token')]);

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

            //Le permito mas memoria a la accion
            ini_set('memory_limit', '-1');
            
            $propuesta = Propuestas::findFirst($request->getPut('id'));

            if (isset($propuesta->id)) {
                
                //Valido para que genere el reporte solo al momento de 
                //clic en generar reporte al inscribir la propuesta por primera vez
                $estado = $propuesta->estado;
                $titulo_reporte="CERTIFICADO DE INSCRIPCIÓN";
                $generar = $request->getPut('vi');
                $parrafo_1="Su inscripción ha sido realizada correctamente. Recuerde que con la inscripción, su propuesta pasa al período de revisión de los requisitos formales del concurso, pero deberá estar atento en caso de que le sea solicitada la subsanación de alguno de los documentos.";
                if($generar==1)
                {
                    $estado=8;
                    $titulo_reporte="CERTIFICADO DE PRE-INSCRIPCIÓN";
                    $parrafo_1="Su inscripción no ha sido confirmada. Recuerde que con la inscripción, su propuesta pasa al período de revisión de los requisitos formales del concurso, pero deberá estar atento en caso de que le sea solicitada la subsanación de alguno de los documentos.";
                }
                
                if ($estado <> 7 && $estado <> 20) {
                    $array_administrativos = array();
                    $array_tecnicos = array();
                    foreach ($propuesta->Propuestasdocumentos as $propuestadocumento) {
                        if ($propuestadocumento->getConvocatoriasdocumentos()->getRequisitos()->tipo_requisito == "Administrativos" AND $propuestadocumento->cargue_subsanacion == false AND $propuestadocumento->active == true) {
                            $array_administrativos[$propuestadocumento->id]["requisito"] = $propuestadocumento->getConvocatoriasdocumentos()->getRequisitos()->nombre;
                            $array_administrativos[$propuestadocumento->id]["nombre"] = $propuestadocumento->nombre;
                        }

                        if ($propuestadocumento->getConvocatoriasdocumentos()->getRequisitos()->tipo_requisito == "Tecnicos" AND $propuestadocumento->active == true) {
                            $array_tecnicos[$propuestadocumento->id]["requisito"] = $propuestadocumento->getConvocatoriasdocumentos()->getRequisitos()->nombre;
                            $array_tecnicos[$propuestadocumento->id]["nombre"] = $propuestadocumento->nombre;
                        }
                    }

                    $array_administrativos_link = array();
                    $array_tecnicos_link = array();
                    foreach ($propuesta->Propuestaslinks as $propuestalink) {
                        if ($propuestalink->getConvocatoriasdocumentos()->getRequisitos()->tipo_requisito == "Administrativos" AND $propuestalink->cargue_subsanacion == false  AND $propuestalink->active == true) {
                            $array_administrativos_link[$propuestalink->id]["requisito"] = $propuestalink->getConvocatoriasdocumentos()->getRequisitos()->nombre;
                            $array_administrativos_link[$propuestalink->id]["link"] = $propuestalink->link;
                        }

                        if ($propuestalink->getConvocatoriasdocumentos()->getRequisitos()->tipo_requisito == "Tecnicos" AND $propuestalink->active == true) {
                            $array_tecnicos_link[$propuestalink->id]["requisito"] = $propuestalink->getConvocatoriasdocumentos()->getRequisitos()->nombre;
                            $array_tecnicos_link[$propuestalink->id]["link"] = $propuestalink->link;
                        }
                    }

                    $html_administrativos = "";
                    $i = 1;
                    foreach ($array_administrativos as $key => $val) {
                        $html_administrativos = $html_administrativos . "<tr>";
                        $html_administrativos = $html_administrativos . "<td>" . $i . "</td>";
                        $html_administrativos = $html_administrativos . "<td>" . $val["requisito"] . "</td>";
                        $html_administrativos = $html_administrativos . "<td>" . $val["nombre"] . "</td>";
                        $html_administrativos = $html_administrativos . "</tr>";
                        $i++;
                    }

                    $html_administrativos_link = "";
                    $i = 1;
                    foreach ($array_administrativos_link as $key => $val) {
                        $html_administrativos_link = $html_administrativos_link . "<tr>";
                        $html_administrativos_link = $html_administrativos_link . "<td>" . $i . "</td>";
                        $html_administrativos_link = $html_administrativos_link . "<td>" . $val["requisito"] . "</td>";
                        $html_administrativos_link = $html_administrativos_link . "<td>" . $val["link"] . "</td>";
                        $html_administrativos_link = $html_administrativos_link . "</tr>";
                        $i++;
                    }

                    $html_tecnicos = "";
                    $i = 1;
                    foreach ($array_tecnicos as $key => $val) {
                        $html_tecnicos = $html_tecnicos . "<tr>";
                        $html_tecnicos = $html_tecnicos . "<td>" . $i . "</td>";
                        $html_tecnicos = $html_tecnicos . "<td>" . $val["requisito"] . "</td>";
                        $html_tecnicos = $html_tecnicos . "<td>" . $val["nombre"] . "</td>";
                        $html_tecnicos = $html_tecnicos . "</tr>";
                        $i++;
                    }

                    $html_tecnicos_link = "";
                    $i = 1;
                    foreach ($array_tecnicos_link as $key => $val) {
                        $html_tecnicos_link = $html_tecnicos_link . "<tr>";
                        $html_tecnicos_link = $html_tecnicos_link . "<td>" . $i . "</td>";
                        $html_tecnicos_link = $html_tecnicos_link . "<td>" . $val["requisito"] . "</td>";
                        $html_tecnicos_link = $html_tecnicos_link . "<td>" . $val["link"] . "</td>";
                        $html_tecnicos_link = $html_tecnicos_link . "</tr>";
                        $i++;
                    }

                    $bogota = ($propuesta->bogota) ? "Si" : "No";

                    //Si la convocatoria seleccionada es categoria, debo invertir los nombres la convocatoria con la categoria
                    $nombre_convocatoria = $propuesta->getConvocatorias()->nombre;
                    $nombre_categoria = "";
                    if ($propuesta->getConvocatorias()->convocatoria_padre_categoria > 0) {
                        $nombre_convocatoria = $propuesta->getConvocatorias()->getConvocatorias()->nombre;
                        $nombre_categoria = $propuesta->getConvocatorias()->nombre;
                    }

                    $participante = $propuesta->getParticipantes()->primer_nombre . " " . $propuesta->getParticipantes()->segundo_nombre . " " . $propuesta->getParticipantes()->primer_apellido . " " . $propuesta->getParticipantes()->segundo_apellido;

                    //Creo la tabla deacuerdo al tipo de participante
                    //Participante natural
                    if ($propuesta->getParticipantes()->getUsuariosperfiles()->getPerfiles()->id == 6) {
                        $tabla_participante = '<table>
    <tr>
        <td>Tipo de documento de identificación</td>
        <td>' . $propuesta->getParticipantes()->getTiposdocumentos()->descripcion . '</td>    
        <td>Número de documento de identificación</td>
        <td>' . $propuesta->getParticipantes()->numero_documento . '</td>
    </tr>    
    <tr>
        <td>Primer nombre</td>
        <td>' . $propuesta->getParticipantes()->primer_nombre . '</td>
        <td>Segundo nombre</td>
        <td>' . $propuesta->getParticipantes()->segundo_nombre . '</td>
    </tr>    
    <tr>
        <td>Primer apellido</td>
        <td>' . $propuesta->getParticipantes()->primer_apellido . '</td>
        <td>Segundo apellido</td>
        <td>' . $propuesta->getParticipantes()->segundo_apellido . '</td>
    </tr>    
    <tr>
        <td>Sexo</td>
        <td>' . $propuesta->getParticipantes()->getSexos()->nombre . '</td>
        <td>Orientación Sexual</td>
        <td>' . $propuesta->getParticipantes()->getOrientacionessexuales()->nombre . '</td>
    </tr>    
    <tr>
        <td>Identidad de género</td>
        <td>' . $propuesta->getParticipantes()->getIdentidadesgeneros()->nombre . '</td>
        <td>Grupo étnico</td>
        <td>' . $propuesta->getParticipantes()->getGruposetnicos()->nombre . '</td>
    </tr>    
    <tr>
        <td>Fecha de nacimiento</td>
        <td>' . $propuesta->getParticipantes()->fecha_nacimiento . '</td>
        <td>Ciudad de nacimiento</td>
        <td>' . $propuesta->getParticipantes()->getCiudadesnacimiento()->nombre . '</td>
    </tr>    
    <tr>
        <td>Ciudad de residencia</td>
        <td>' . $propuesta->getParticipantes()->getCiudadesresidencia()->nombre . '</td>
        <td>Barrio residencia</td>
        <td>' . $propuesta->getParticipantes()->getBarriosresidencia()->nombre . '</td>
    </tr>    
    <tr>
        <td>Dirección de residencia</td>
        <td>' . $propuesta->getParticipantes()->direccion_residencia . '</td>
        <td>Dirección correspondencia</td>
        <td>' . $propuesta->getParticipantes()->direccion_correspondencia . '</td>
    </tr>    
    <tr>
        <td>Estrato</td>
        <td>' . $propuesta->getParticipantes()->estrato . '</td>
        <td>Teléfono fijo</td>
        <td>' . $propuesta->getParticipantes()->numero_telefono . '</td>
    </tr>    
    <tr>
        <td>Número de celular personal</td>
        <td>' . $propuesta->getParticipantes()->numero_celular . '</td>
        <td>Correo electrónico</td>
        <td>' . $propuesta->getParticipantes()->correo_electronico . '</td>
    </tr>    
    <tr>
        <td>Redes sociales</td>
        <td>' . $propuesta->getParticipantes()->facebook . '</td>
        <td>Página web, vínculo o blog</td>
        <td>' . $propuesta->getParticipantes()->links . '</td>
    </tr>           
</table>';
                    }
                    //Participante juridico
                    if ($propuesta->getParticipantes()->getUsuariosperfiles()->getPerfiles()->id == 7) {
                        $conditions = ['id' => $propuesta->participante, 'participante_padre' => $propuesta->participante, 'tipo' => 'Junta', 'active' => true];

                        //Se crea todo el array de las rondas de evaluacion
                        $consulta_integrantes = Participantes::find(([
                                    'conditions' => 'id<>:id: AND participante_padre=:participante_padre: AND tipo=:tipo: AND active=:active:',
                                    'bind' => $conditions,
                                    "order" => 'representante DESC'
                        ]));

                        $i = 1;
                        $html_integrantes = "";
                        foreach ($consulta_integrantes as $integrante) {
                            $value_representante="No";
                            if($integrante->representante)
                            {
                                $value_representante="Sí";
                            }
                            
                            $html_integrantes = $html_integrantes . "<tr>";
                            $html_integrantes = $html_integrantes . "<td>" . $i . "</td>";
                            $html_integrantes = $html_integrantes . "<td>" . $value_representante . "</td>";
                            $html_integrantes = $html_integrantes . "<td>" . $integrante->getTiposdocumentos()->descripcion . "</td>";
                            $html_integrantes = $html_integrantes . "<td>" . $integrante->numero_documento . "</td>";
                            $html_integrantes = $html_integrantes . "<td>" . $integrante->primer_nombre . " " . $integrante->segundo_nombre . "</td>";
                            $html_integrantes = $html_integrantes . "<td>" . $integrante->primer_apellido . " " . $integrante->segundo_apellido . "</td>";
                            $html_integrantes = $html_integrantes . "<td>" . $integrante->getCiudadesresidencia()->nombre . "</td>";                            
                            $html_integrantes = $html_integrantes . "<td>" . $integrante->rol . "</td>";
                            $html_integrantes = $html_integrantes . "</tr>";
                            $i++;
                        }
                        
                        $conditions = ['id' => $propuesta->participante, 'participante_padre' => $propuesta->participante, 'tipo' => 'EquipoTrabajo', 'active' => true];
                        
                        //Se crea todo el array de las rondas de evaluacion
                        $consulta_equipo_trabajo = Participantes::find(([
                                    'conditions' => 'id<>:id: AND participante_padre=:participante_padre: AND tipo=:tipo: AND active=:active:',
                                    'bind' => $conditions,
                                    "order" => 'representante DESC'
                        ]));

                        $i = 1;
                        $html_equipo = "";
                        foreach ($consulta_equipo_trabajo as $integrante) {
                            $value_representante="No";
                            if($integrante->director)
                            {
                                $value_representante="Sí";
                            }
                            
                            $html_equipo = $html_equipo . "<tr>";
                            $html_equipo = $html_equipo . "<td>" . $i . "</td>";  
                            $html_equipo = $html_equipo . "<td>" . $value_representante . "</td>";
                            $html_equipo = $html_equipo . "<td>" . $integrante->getTiposdocumentos()->descripcion . "</td>";
                            $html_equipo = $html_equipo . "<td>" . $integrante->numero_documento . "</td>";
                            $html_equipo = $html_equipo . "<td>" . $integrante->primer_nombre . " " . $integrante->segundo_nombre . "</td>";
                            $html_equipo = $html_equipo . "<td>" . $integrante->primer_apellido . " " . $integrante->segundo_apellido . "</td>";
                            $html_equipo = $html_equipo . "<td>" . $integrante->getCiudadesresidencia()->nombre . "</td>";                            
                            $html_equipo = $html_equipo . "<td>" . $integrante->rol . "</td>";
                            $html_equipo = $html_equipo . "<td>" . $integrante->profesion . "</td>";
                            $html_equipo = $html_equipo . "<td>" . $integrante->experiencia . "</td>";
                            $html_equipo = $html_equipo . "<td>" . $integrante->actividades_cargo . "</td>";
                            $html_equipo = $html_equipo . "</tr>";
                            $i++;
                        }


                        $cuenta_sede = ($propuesta->getParticipantes()->cuenta_sede) ? 'Sí' : 'No';
                        
                        
                        //Consultamos los objetivos especificos
                        $conditions = ['propuesta' => $propuesta->id, 'active' => true];
                        
                        //Se crea todo el array de las rondas de evaluacion
                        $consulta_objetivos_especificos = Propuestasobjetivos::find(([
                                    'conditions' => 'propuesta=:propuesta: AND active=:active:',
                                    'bind' => $conditions,
                                    "order" => 'orden DESC'
                        ]));
                        
                        $html_objetivo = "<table>";
                        foreach ($consulta_objetivos_especificos as $objetivo) {
                            $html_objetivo = $html_objetivo . "<tr>";                            
                            $html_objetivo = $html_objetivo . "<td>Objetivo especifico</td><td>" . $objetivo->objetivo . "</td>";                                                                                    
                            $html_objetivo = $html_objetivo . "</tr>";         
                            $html_objetivo = $html_objetivo . "<tr>";         
                            $html_objetivo = $html_objetivo . "<td>Meta</td><td>" . $objetivo->meta . "</td>";                                                        
                            $html_objetivo = $html_objetivo . "</tr>";                            
                            $html_objetivo = $html_objetivo . "<tr>";
                            $html_objetivo = $html_objetivo . '<td colspan="2" bgcolor="#BDBDBD"><b>Actividades</b></td>';
                            $html_objetivo = $html_objetivo . "</tr>";
                            $html_objetivo = $html_objetivo . '<tr><td colspan="2">';                            
                            foreach ($objetivo->getPropuestasactividades(['conditions' => 'active=TRUE',"order" => 'orden DESC']) as $actividad) {
                            $html_objetivo = $html_objetivo . "" . $actividad->actividad . "<br/><br/>";                                                                                                                
                            }                            
                            $html_objetivo = $html_objetivo . "</td></tr>";                                                        
                        }                        
                        $html_objetivo = $html_objetivo."</table>";
                        
                        $html_cronograma = "";
                        foreach ($consulta_objetivos_especificos as $objetivo) {
                            $array_ejecucion=array();
                            $array_ejecucion[1]=array("1"=>"","2"=>"","3"=>"","4"=>"","5"=>"");
                            $array_ejecucion[2]=array("1"=>"","2"=>"","3"=>"","4"=>"","5"=>"");
                            $array_ejecucion[3]=array("1"=>"","2"=>"","3"=>"","4"=>"","5"=>"");
                            $array_ejecucion[4]=array("1"=>"","2"=>"","3"=>"","4"=>"","5"=>"");
                            $array_ejecucion[5]=array("1"=>"","2"=>"","3"=>"","4"=>"","5"=>"");
                            $array_ejecucion[6]=array("1"=>"","2"=>"","3"=>"","4"=>"","5"=>"");
                            $array_ejecucion[7]=array("1"=>"","2"=>"","3"=>"","4"=>"","5"=>"");
                            $array_ejecucion[8]=array("1"=>"","2"=>"","3"=>"","4"=>"","5"=>"");
                            $array_ejecucion[9]=array("1"=>"","2"=>"","3"=>"","4"=>"","5"=>"");
                            $array_ejecucion[10]=array("1"=>"","2"=>"","3"=>"","4"=>"","5"=>"");
                            $array_ejecucion[11]=array("1"=>"","2"=>"","3"=>"","4"=>"","5"=>"");
                            $array_ejecucion[12]=array("1"=>"","2"=>"","3"=>"","4"=>"","5"=>"");                            
                            
                            $html_cronograma = $html_cronograma . "<br/><br/><table>";                            
                            $html_cronograma = $html_cronograma . "<tr>";                            
                            $html_cronograma = $html_cronograma . '<td colspan="64">Objetivo especifico: ' . $objetivo->objetivo . '</td>';
                            $html_cronograma = $html_cronograma . "</tr>";         
                            $html_cronograma = $html_cronograma . '<tr><td colspan="4" align="center" bgcolor="#BDBDBD">Mes</td><td colspan="5" align="center" bgcolor="#BDBDBD">Enero</td><td colspan="5" align="center" bgcolor="#BDBDBD">Febrero</td><td colspan="5" align="center" bgcolor="#BDBDBD">Marzo</td><td colspan="5" align="center" bgcolor="#BDBDBD">Abril</td><td colspan="5" align="center" bgcolor="#BDBDBD">Mayo</td><td colspan="5" align="center" bgcolor="#BDBDBD">Junio</td><td colspan="5" align="center" bgcolor="#BDBDBD">Julio</td><td colspan="5" align="center" bgcolor="#BDBDBD">Agosto</td><td colspan="5" align="center" bgcolor="#BDBDBD">Septiembre</td><td colspan="5" align="center" bgcolor="#BDBDBD">Octubre</td><td colspan="5" align="center" bgcolor="#BDBDBD">Noviembre</td><td colspan="5" align="center" bgcolor="#BDBDBD">Diciembre</td></tr>';
                            $html_cronograma = $html_cronograma . '<tr><td colspan="4" align="center" bgcolor="#BDBDBD">Semana</td><td align="center" bgcolor="#BDBDBD">1</td><td align="center" bgcolor="#BDBDBD">2</td><td align="center" bgcolor="#BDBDBD">3</td><td align="center" bgcolor="#BDBDBD">4</td><td align="center" bgcolor="#BDBDBD">5</td><td align="center" bgcolor="#BDBDBD">1</td><td align="center" bgcolor="#BDBDBD">2</td><td align="center" bgcolor="#BDBDBD">3</td><td align="center" bgcolor="#BDBDBD">4</td><td align="center" bgcolor="#BDBDBD">5</td><td align="center" bgcolor="#BDBDBD">1</td><td align="center" bgcolor="#BDBDBD">2</td><td align="center" bgcolor="#BDBDBD">3</td><td align="center" bgcolor="#BDBDBD">4</td><td align="center" bgcolor="#BDBDBD">5</td><td align="center" bgcolor="#BDBDBD">1</td><td align="center" bgcolor="#BDBDBD">2</td><td align="center" bgcolor="#BDBDBD">3</td><td align="center" bgcolor="#BDBDBD">4</td><td align="center" bgcolor="#BDBDBD">5</td><td align="center" bgcolor="#BDBDBD">1</td><td align="center" bgcolor="#BDBDBD">2</td><td align="center" bgcolor="#BDBDBD">3</td><td align="center" bgcolor="#BDBDBD">4</td><td align="center" bgcolor="#BDBDBD">5</td><td align="center" bgcolor="#BDBDBD">1</td><td align="center" bgcolor="#BDBDBD">2</td><td align="center" bgcolor="#BDBDBD">3</td><td align="center" bgcolor="#BDBDBD">4</td><td align="center" bgcolor="#BDBDBD">5</td><td align="center" bgcolor="#BDBDBD">1</td><td align="center" bgcolor="#BDBDBD">2</td><td align="center" bgcolor="#BDBDBD">3</td><td align="center" bgcolor="#BDBDBD">4</td><td align="center" bgcolor="#BDBDBD">5</td><td align="center" bgcolor="#BDBDBD">1</td><td align="center" bgcolor="#BDBDBD">2</td><td align="center" bgcolor="#BDBDBD">3</td><td align="center" bgcolor="#BDBDBD">4</td><td align="center" bgcolor="#BDBDBD">5</td><td align="center" bgcolor="#BDBDBD">1</td><td align="center" bgcolor="#BDBDBD">2</td><td align="center" bgcolor="#BDBDBD">3</td><td align="center" bgcolor="#BDBDBD">4</td><td align="center" bgcolor="#BDBDBD">5</td><td align="center" bgcolor="#BDBDBD">1</td><td align="center" bgcolor="#BDBDBD">2</td><td align="center" bgcolor="#BDBDBD">3</td><td align="center" bgcolor="#BDBDBD">4</td><td align="center" bgcolor="#BDBDBD">5</td><td align="center" bgcolor="#BDBDBD">1</td><td align="center" bgcolor="#BDBDBD">2</td><td align="center" bgcolor="#BDBDBD">3</td><td align="center" bgcolor="#BDBDBD">4</td><td align="center" bgcolor="#BDBDBD">5</td><td align="center" bgcolor="#BDBDBD">1</td><td align="center" bgcolor="#BDBDBD">2</td><td align="center" bgcolor="#BDBDBD">3</td><td align="center" bgcolor="#BDBDBD">4</td><td align="center" bgcolor="#BDBDBD">5</td></tr>';
                            foreach ($objetivo->getPropuestasactividades(['conditions' => 'active=TRUE',"order" => 'orden DESC']) as $actividad) {                                                                                        
                            $html_cronograma = $html_cronograma . '<tr><td colspan="64">' . $actividad->actividad . '</td></tr>';
                                foreach ($actividad->getPropuestascronogramas(['conditions' => 'active=TRUE',"order" => 'fecha DESC']) as $fecha) {
                                    $numero_semana=weekOfMonth($fecha->fecha);
                                    $numero_mes=$mes = (Integer)date("m", strtotime($fecha->fecha));                                                                        
                                    $array_ejecucion[$numero_mes][$numero_semana]="X";
                                }                                                        
                            }       
                            $html_cronograma = $html_cronograma . '<tr><td colspan="4"></td>';
                            foreach ($array_ejecucion AS $clave=>$valor)
                            {
                                $color_1="";
                                if($valor[1]=="X")
                                {
                                    $color_1='align="center" bgcolor="#BDBDBD"';
                                }
                                
                                $color_2="";
                                if($valor[2]=="X")
                                {
                                    $color_2='align="center" bgcolor="#BDBDBD"';
                                }
                                
                                $color_3="";
                                if($valor[3]=="X")
                                {
                                    $color_3='align="center" bgcolor="#BDBDBD"';
                                }
                                
                                $color_4="";
                                if($valor[4]=="X")
                                {
                                    $color_4='align="center" bgcolor="#BDBDBD"';
                                }
                                
                                $color_5="";
                                if($valor[5]=="X")
                                {
                                    $color_5='align="center" bgcolor="#BDBDBD"';
                                }
                                
                                $html_cronograma = $html_cronograma . '<td '.$color_1.'>'.$valor[1].'</td>'. '<td '.$color_2.'>'.$valor[2].'</td>'. '<td '.$color_3.'>'.$valor[3].'</td>'. '<td '.$color_4.'>'.$valor[4].'</td>'. '<td '.$color_5.'>'.$valor[5].'</td>';
                            }
                            $html_cronograma = $html_cronograma . '</tr>';
                            $html_cronograma = $html_cronograma . "</table>";                                                        
                        } 
                        
                        $html_presupuesto = "";
                        $valortotal_proyecto=0;
                        $aportesolicitado_proyecto=0;
                        $aportecofinanciado_proyecto=0;
                        $aportepropio_proyecto=0;
                        foreach ($consulta_objetivos_especificos as $objetivo) {
                            $html_presupuesto = $html_presupuesto . "<br/><br/><table>";                            
                            $html_presupuesto = $html_presupuesto . '<tr><td align="center" bgcolor="#BDBDBD">Insumo</td><td align="center" bgcolor="#BDBDBD">Cantidad</td><td align="center" bgcolor="#BDBDBD">Valor Total</td><td align="center" bgcolor="#BDBDBD">Valor Solicitado</td><td align="center" bgcolor="#BDBDBD">Valor Cofinanciado</td><td align="center" bgcolor="#BDBDBD">Valor Aportado Participante</td></tr>';                            
                            $html_presupuesto = $html_presupuesto . '<tr><td colspan="4">Objetivo especifico: ' . $objetivo->objetivo . '</td></tr>';                            
                            foreach ($objetivo->getPropuestasactividades(['conditions' => 'active=TRUE',"order" => 'orden DESC']) as $actividad) {                                                                                        
                            $html_presupuesto = $html_presupuesto . '<tr><td colspan="4">' . $actividad->actividad . '</td></tr>';
                                $valortotal=0;
                                $aportesolicitado=0;
                                $aportecofinanciado=0;
                                $aportepropio=0;
                                foreach ($actividad->getPropuestaspresupuestos(['conditions' => 'active=TRUE',"order" => 'insumo DESC']) as $presupuesto) {
                                    $valortotal=$valortotal+$presupuesto->valortotal;
                                    $aportesolicitado=$aportesolicitado+$presupuesto->aportesolicitado;
                                    $aportecofinanciado=$aportecofinanciado+$presupuesto->aportecofinanciado;
                                    $aportepropio=$aportepropio+$presupuesto->aportepropio;
                                    $html_presupuesto = $html_presupuesto . '<tr><td>'.$presupuesto->insumo.'</td><td>'.$presupuesto->cantidad.' ('.$presupuesto->unidadmedida.')</td><td align="right">$'.number_format($presupuesto->valortotal).'</td><td align="right">$'.number_format($presupuesto->aportesolicitado).'</td><td align="right">$'.number_format($presupuesto->aportecofinanciado).'</td><td align="right">$'.number_format($presupuesto->aportepropio).'</td></tr>';
                                }                                                        
                                $html_presupuesto = $html_presupuesto . '<tr><td colspan="2" align="right" bgcolor="#BDBDBD">Totales Actividad</td><td align="right">$'.number_format($valortotal).'</td><td align="right">$'.number_format($aportesolicitado).'</td><td align="right">$'.number_format($aportecofinanciado).'</td><td align="right">$'.number_format($aportepropio).'</td></tr>';
                                $valortotal_proyecto=$valortotal_proyecto+$valortotal;
                                $aportesolicitado_proyecto=$aportesolicitado_proyecto+$aportesolicitado;
                                $aportecofinanciado_proyecto=$aportecofinanciado_proyecto+$aportecofinanciado;
                                $aportepropio_proyecto=$aportepropio_proyecto+$aportepropio;
                            }                            
                            $html_presupuesto = $html_presupuesto . "</table>";                                                        
                        }
                        $html_presupuesto = $html_presupuesto . "<table>";                                                        
                        $html_presupuesto = $html_presupuesto . '<tr><td colspan="2" align="right" bgcolor="#BDBDBD"><b>TOTAL PRESUPUESTO INGRESADO PARA LAS ACTIVIDADES</b></td><td align="right">$'.number_format($valortotal_proyecto).'</td><td align="right">$'.number_format($aportesolicitado_proyecto).'</td><td align="right">$'.number_format($aportecofinanciado_proyecto).'</td><td align="right">$'.number_format($aportepropio_proyecto).'</td></tr>';
                        $html_presupuesto = $html_presupuesto . "</table>";                                                        
                        
                        $utf8_ansi2 = array(
    "\u00c0" =>"À",
    "\u00c1" =>"Á",
    "\u00c2" =>"Â",
    "\u00c3" =>"Ã",
    "\u00c4" =>"Ä",
    "\u00c5" =>"Å",
    "\u00c6" =>"Æ",
    "\u00c7" =>"Ç",
    "\u00c8" =>"È",
    "\u00c9" =>"É",
    "\u00ca" =>"Ê",
    "\u00cb" =>"Ë",
    "\u00cc" =>"Ì",
    "\u00cd" =>"Í",
    "\u00ce" =>"Î",
    "\u00cf" =>"Ï",
    "\u00d1" =>"Ñ",
    "\u00d2" =>"Ò",
    "\u00d3" =>"Ó",
    "\u00d4" =>"Ô",
    "\u00d5" =>"Õ",
    "\u00d6" =>"Ö",
    "\u00d8" =>"Ø",
    "\u00d9" =>"Ù",
    "\u00da" =>"Ú",
    "\u00db" =>"Û",
    "\u00dc" =>"Ü",
    "\u00dd" =>"Ý",
    "\u00df" =>"ß",
    "\u00e0" =>"à",
    "\u00e1" =>"á",
    "\u00e2" =>"â",
    "\u00e3" =>"ã",
    "\u00e4" =>"ä",
    "\u00e5" =>"å",
    "\u00e6" =>"æ",
    "\u00e7" =>"ç",
    "\u00e8" =>"è",
    "\u00e9" =>"é",
    "\u00ea" =>"ê",
    "\u00eb" =>"ë",
    "\u00ec" =>"ì",
    "\u00ed" =>"í",
    "\u00ee" =>"î",
    "\u00ef" =>"ï",
    "\u00f0" =>"ð",
    "\u00f1" =>"ñ",
    "\u00f2" =>"ò",
    "\u00f3" =>"ó",
    "\u00f4" =>"ô",
    "\u00f5" =>"õ",
    "\u00f6" =>"ö",
    "\u00f8" =>"ø",
    "\u00f9" =>"ù",
    "\u00fa" =>"ú",
    "\u00fb" =>"û",
    "\u00fc" =>"ü",
    "\u00fd" =>"ý",
    "\u00ff" =>"ÿ");  
                        
                        $propuesta_localidades=str_replace('","', " , ", $propuesta->localidades );
                        $propuesta_localidades=str_replace('["', "", $propuesta_localidades);
                        $propuesta_localidades=str_replace('"]', "", $propuesta_localidades);    

//Consultamos los territorios
                        
$conditions = ['propuesta' => $propuesta->id];

//Se crea todo el array de las rondas de evaluacion
$consulta_territorios = Propuestasterritorios::find(([
            'conditions' => 'propuesta=:propuesta:',
            'bind' => $conditions
]));                        
                     
$array_territorios=array();
foreach ($consulta_territorios as $territorio) {
    $array_territorios[$territorio->variable]=$territorio->valor;
}
                        
                        
                        $tabla_participante = '<table>
    <tr>
        <td>Tipo de documento de identificación</td>
        <td>' . $propuesta->getParticipantes()->getTiposdocumentos()->descripcion . '</td>    
        <td>Número de Nit</td>
        <td>' . $propuesta->getParticipantes()->numero_documento . '</td>
    </tr>    
    <tr>
        <td>DV</td>
        <td>' . $propuesta->getParticipantes()->dv . '</td>
        <td>Razón Social</td>
        <td>' . $propuesta->getParticipantes()->primer_nombre . '</td>
    </tr>       
    <tr>
        <td>Municipio</td>
        <td>' . $propuesta->getParticipantes()->getCiudadesresidencia()->nombre . '</td>
        <td>Barrio</td>
        <td>' . $propuesta->getParticipantes()->getBarriosresidencia()->nombre . '</td>
    </tr>    
    <tr>
        <td>Estrato</td>
        <td>' . $propuesta->getParticipantes()->estrato . '</td>
        <td>Dirección</td>
        <td>' . $propuesta->getParticipantes()->direccion_residencia . '</td>        
    </tr>    
    <tr>
        <td>Teléfono fijo</td>
        <td>' . $propuesta->getParticipantes()->numero_telefono . '</td>
        <td>Número de celular</td>
        <td>' . $propuesta->getParticipantes()->numero_celular . '</td>
    </tr>    
    <tr>
        <td>Objeto Social</td>
        <td>' . $propuesta->getParticipantes()->objeto_social . '</td>
        <td>Fecha de Constitución</td>
        <td>' . $propuesta->getParticipantes()->fecha_nacimiento . '</td>
    </tr>    
    <tr>
        <td>Correo electrónico</td>
        <td>' . $propuesta->getParticipantes()->correo_electronico . '</td>
        <td>¿Cuenta con sede?</td>
        <td>' . $cuenta_sede . '</td>
    </tr>    
    <tr>
        <td>Tipo de sede</td>
        <td>' . $propuesta->getParticipantes()->tipo_sede . '</td>
        <td>Redes sociales</td>
        <td>' . $propuesta->getParticipantes()->facebook . '</td>        
    </tr> 
    <tr>
        <td>Página web, vínculo o blog</td>
        <td>' . $propuesta->getParticipantes()->links . '</td>
    </tr> 
</table>
<h3>Junta directiva</h3>
<table>    
    <tr>
        <td align="center" bgcolor="#BDBDBD">N°</td>
        <td align="center" bgcolor="#BDBDBD">¿Representante?</td>    
        <td align="center" bgcolor="#BDBDBD">Tipo de documento</td>    
        <td align="center" bgcolor="#BDBDBD">Número de documento de identificación</td>    
        <td align="center" bgcolor="#BDBDBD">Nombres</td>        
        <td align="center" bgcolor="#BDBDBD">Apellidos</td>        
        <td align="center" bgcolor="#BDBDBD">Ciudad de residencia</td>        
        <td align="center" bgcolor="#BDBDBD">Rol que desempeña o ejecuta en la propuesta</td>                
    </tr> 
    ' . $html_integrantes . '
</table>
<h3>Equipo de trabajo</h3>
<table>    
    <tr>
        <td align="center" bgcolor="#BDBDBD">N°</td>        
        <td align="center" bgcolor="#BDBDBD">¿Director del Proyecto?</td>    
        <td align="center" bgcolor="#BDBDBD">Tipo de documento</td>    
        <td align="center" bgcolor="#BDBDBD">Número de documento de identificación</td>    
        <td align="center" bgcolor="#BDBDBD">Nombres</td>        
        <td align="center" bgcolor="#BDBDBD">Apellidos</td>        
        <td align="center" bgcolor="#BDBDBD">Ciudad de residencia</td>        
        <td align="center" bgcolor="#BDBDBD">Rol que desempeña o ejecuta en la propuesta</td>                
        <td align="center" bgcolor="#BDBDBD">Profesión</td>                
        <td align="center" bgcolor="#BDBDBD">Experiencia</td>                
        <td align="center" bgcolor="#BDBDBD">Actividades a cargo</td>                
    </tr> 
    ' . $html_equipo . '
</table>
<h3>Objetivo general</h3>
<table>
    <tr>        
        <td colspan="4">' . $propuesta->objetivo_general . '</td>        
    </tr>    
</table>
<h3>Objetivos Específicos, metas y actividades</h3>
'.$html_objetivo.'
<h3>Cronograma</h3>    
'.$html_cronograma.'
<h3>Presupuesto por actividades</h3>    
'.$html_presupuesto.'
<h3>Territorio y población</h3>    
<table>
    <tr>
        <td>Localidad principal en donde el proyecto desarrollará las acciones</td><td colspan="3">'.$propuesta->getLocalidades()->nombre.'</td>
    </tr>
    <tr>
        <td>localidades en donde el proyecto desarrollará acciones</td><td colspan="3">' . strtr($propuesta_localidades, $utf8_ansi2) . '</td>
    </tr>
</table>    
<h3>Participantes y/o beneficiarios</h3>    
<table>    
    <tr>
        <td>Describa brevemente la población objetivo del proyecto</td>
        <td colspan="3">' . $propuesta->poblacion_objetivo . '</td>        
    </tr> 
    <tr>
        <td>¿Cómo se concertó el proyecto con la comunidad objetivo?</td>
        <td colspan="3">' . $propuesta->comunidad_objetivo . '</td>        
    </tr> 
    <tr>
        <td>Estimado total de beneficiarios o participantes</td>
        <td colspan="3">' . $propuesta->total_beneficiario . '</td>        
    </tr> 
    <tr>
        <td>Cómo se estableció esta cifra</td>
        <td colspan="3">' . $propuesta->establecio_cifra . '</td>        
    </tr> 
</table>
<h3>Caracterización de la población</h3>    
<table>    
    <tr>        
        <td colspan="2" bgcolor="#BDBDBD">Sexo</td>        
    </tr>     
    <tr>        
        <td>Femenino</td>        
        <td>'.$array_territorios["femenino"].'</td>        
    </tr>     
    <tr>        
        <td>Intersexual</td>        
        <td>'.$array_territorios["intersexual"].'</td>        
    </tr>     
    <tr>        
        <td>Masculino</td>        
        <td>'.$array_territorios["masculino"].'</td>        
    </tr>     
</table>
<br/><br/>
<table>    
    <tr>        
        <td colspan="2" bgcolor="#BDBDBD">Grupo etareo</td>        
    </tr>     
    <tr>        
        <td>Primera infancia (0 – 5 años)</td>        
        <td>'.$array_territorios["primera_infancia"].'</td>        
    </tr>     
    <tr>        
        <td>Infancia (6 – 12 años)</td>        
        <td>'.$array_territorios["infancia"].'</td>        
    </tr>     
    <tr>        
        <td>Adolescencia (13 – 18 años)</td>        
        <td>'.$array_territorios["adolescencia"].'</td>        
    </tr>     
    <tr>        
        <td>Juventud (19 – 28 años)</td>        
        <td>'.$array_territorios["juventud"].'</td>        
    </tr>     
    <tr>        
        <td>Adulto (29 – 59 años)</td>        
        <td>'.$array_territorios["adulto"].'</td>        
    </tr>     
    <tr>        
        <td>Adulto mayor (60 años y más)</td>        
        <td>'.$array_territorios["adulto_mayor"].'</td>        
    </tr>     
</table>
<br/><br/>
<table>    
    <tr>        
        <td colspan="2" bgcolor="#BDBDBD">Estrato</td>        
    </tr>     
    <tr>        
        <td>1</td>        
        <td>'.$array_territorios["estrato_1"].'</td>        
    </tr>     
    <tr>        
        <td>2</td>        
        <td>'.$array_territorios["estrato_2"].'</td>        
    </tr>     
    <tr>        
        <td>3</td>        
        <td>'.$array_territorios["estrato_3"].'</td>        
    </tr>     
    <tr>        
        <td>4</td>        
        <td>'.$array_territorios["estrato_4"].'</td>        
    </tr>     
    <tr>        
        <td>5</td>        
        <td>'.$array_territorios["estrato_5"].'</td>        
    </tr>     
    <tr>        
        <td>6</td>        
        <td>'.$array_territorios["estrato_6"].'</td>        
    </tr>     
</table>
<br/><br/>
<table>    
    <tr>        
        <td colspan="2" bgcolor="#BDBDBD">Grupos étnico</td>        
    </tr>     
    <tr>        
        <td>Comunidades Negras o Afrocolombianas</td>        
        <td>'.$array_territorios["comunidades_negras_afrocolombianas"].'</td>        
    </tr>     
    <tr>        
        <td>Comunidad raizal</td>        
        <td>'.$array_territorios["comunidad_raizal"].'</td>        
    </tr>     
    <tr>        
        <td>Pueblos y Comunidades Indígenas</td>        
        <td>'.$array_territorios["pueblos_comunidades_indigenas"].'</td>        
    </tr>     
    <tr>        
        <td>Pueblo Rom o Gitano</td>        
        <td>'.$array_territorios["pueblo_rom_gitano"].'</td>        
    </tr>     
    <tr>        
        <td>Mestizo</td>        
        <td>'.$array_territorios["mestizo"].'</td>        
    </tr>     
    <tr>        
        <td>Ninguno</td>        
        <td>'.$array_territorios["ninguno_etnico"].'</td>        
    </tr>     
</table>
<br/><br/>
<table>    
    <tr>        
        <td colspan="2" bgcolor="#BDBDBD">Grupos sociales y poblacionales</td>        
    </tr>     
    <tr>        
        <td>Artesanos</td>        
        <td>'.$array_territorios["artesanos"].'</td>        
    </tr>     
    <tr>        
        <td>Discapacitados</td>        
        <td>'.$array_territorios["discapacitados"].'</td>        
    </tr>     
    <tr>        
        <td>Habitantes de calle</td>        
        <td>'.$array_territorios["habitantes_calle"].'</td>        
    </tr>     
    <tr>        
        <td>LGBTI</td>        
        <td>'.$array_territorios["lgbti"].'</td>        
    </tr>     
    <tr>        
        <td>Personas de comunidades rurales y campesinas</td>        
        <td>'.$array_territorios["personas_comunidades_rurales_campesinas"].'</td>        
    </tr>     
    <tr>        
        <td>Personas privadas de la libertad</td>        
        <td>'.$array_territorios["personas_privadas_libertad"].'</td>        
    </tr>     
    <tr>        
        <td>Víctimas del conflicto</td>        
        <td>'.$array_territorios["victimas_conflicto"].'</td>        
    </tr>     
    <tr>        
        <td>Ninguno</td>        
        <td>'.$array_territorios["ninguno_grupo"].'</td>        
    </tr>     
</table>


';
                    }
                    //Participante agrupacion
                    if ($propuesta->getParticipantes()->getUsuariosperfiles()->getPerfiles()->id == 8) {

                        $conditions = ['id' => $propuesta->participante, 'participante_padre' => $propuesta->participante, 'tipo' => 'Integrante', 'active' => true];

//Se crea todo el array de las rondas de evaluacion
                        $consulta_integrantes = Participantes::find(([
                                    'conditions' => 'id<>:id: AND participante_padre=:participante_padre: AND tipo=:tipo: AND active=:active:',
                                    'bind' => $conditions,
                                    "order" => 'representante DESC'
                        ]));

                        $i = 1;
                        $html_integrantes = "";
                        foreach ($consulta_integrantes as $integrante) {
                            $value_representante="No";
                            if($integrante->representante)
                            {
                                $value_representante="Sí";
                            }
                            
                            $html_integrantes = $html_integrantes . "<tr>";
                            $html_integrantes = $html_integrantes . "<td>" . $i . "</td>";
                            $html_integrantes = $html_integrantes . "<td>" . $value_representante . "</td>";
                            $html_integrantes = $html_integrantes . "<td>" . $integrante->getTiposdocumentos()->descripcion . "</td>";
                            $html_integrantes = $html_integrantes . "<td>" . $integrante->numero_documento . "</td>";
                            $html_integrantes = $html_integrantes . "<td>" . $integrante->primer_nombre . " " . $integrante->segundo_nombre . "</td>";
                            $html_integrantes = $html_integrantes . "<td>" . $integrante->primer_apellido . " " . $integrante->segundo_apellido . "</td>";
                            $html_integrantes = $html_integrantes . "<td>" . $integrante->getCiudadesresidencia()->nombre . "</td>";                            
                            $html_integrantes = $html_integrantes . "<td>" . $integrante->rol . "</td>";
                            $html_integrantes = $html_integrantes . "</tr>";
                            $i++;
                        }


                        $tabla_participante = '<table>
    <tr>
        <td>Nombre de la agrupación</td>
        <td>' . $propuesta->getParticipantes()->primer_nombre . '</td>
        <td>Correo electrónico de la entidad</td>
        <td>' . $propuesta->getParticipantes()->correo_electronico . '</td>
    </tr>    
    <tr>        
        <td>Redes sociales</td>
        <td>' . $propuesta->getParticipantes()->facebook . '</td>            
        <td>Página web, vínculo o blog</td>
        <td>' . $propuesta->getParticipantes()->links . '</td>
    </tr> 
</table>
<h3>Integrantes</h3>
<table>    
    <tr>
        <td align="center" bgcolor="#BDBDBD">N°</td>
        <td align="center" bgcolor="#BDBDBD">¿Representante?</td>    
        <td align="center" bgcolor="#BDBDBD">Tipo de documento</td>    
        <td align="center" bgcolor="#BDBDBD">Número de documento de identificación</td>    
        <td align="center" bgcolor="#BDBDBD">Nombres</td>        
        <td align="center" bgcolor="#BDBDBD">Apellidos</td>        
        <td align="center" bgcolor="#BDBDBD">Ciudad de residencia</td> 
        <td align="center" bgcolor="#BDBDBD">Rol que desempeña o ejecuta en la propuesta</td>                
    </tr> 
    ' . $html_integrantes . '
</table>
';
                    }


    //Limpieza de variables
    $relacion_plan=str_replace('","', " , ", $propuesta->relacion_plan);
    $relacion_plan=str_replace('["', "", $relacion_plan);
    $relacion_plan=str_replace('"]', "", $relacion_plan);                        
    
    $linea_estrategica=str_replace('","', " , ", $propuesta->linea_estrategica );
    $linea_estrategica=str_replace('["', "", $linea_estrategica);
    $linea_estrategica=str_replace('"]', "", $linea_estrategica);
    
    $porque_medio=str_replace('","', " , ", $propuesta->porque_medio );
    $porque_medio=str_replace('["', "", $porque_medio);
    $porque_medio=str_replace('"]', "", $porque_medio);                        
    
    $area=str_replace('","', " , ", $propuesta->area );
    $area=str_replace('["', "", $area);
    $area=str_replace('"]', "", $area);                        
    
    $alianza_sectorial = ($propuesta->alianza_sectorial) ? 'Sí' : 'No';
    
    $primera_vez_pdac = ($propuesta->primera_vez_pdac) ? 'Sí' : 'No';    
                         
                    $html = '<!-- EXAMPLE OF CSS STYLE -->
<style>
        table {
		font-size: 10pt;	
	}
        
	td {
		border: 1px solid #E3E3E3;	
                background-color: #ffffee;
	}
</style>
<h2  style="text-align:center;">'.$titulo_reporte.'</h2>
<h3>Información de la propuesta</h3>        
<p>'.$parrafo_1.'</p>
<table>
    <tr>
        <td colspan="2"><b>Código</b></td>
        <td colspan="2"><b>' . $propuesta->codigo . '</b></td>            
    </tr>    
    <tr>
        <td>Nombre de la convocatoria</td>
        <td>' . $nombre_convocatoria . '</td>    
        <td>Categoría de la convocatoria</td>
        <td>' . $nombre_categoria . '</td>
    </tr>    
    <tr>
        <td>Nombre del participante</td>
        <td>' . $participante . '</td>
        <td>Tipo de participante</td>
        <td>' . $propuesta->getParticipantes()->getUsuariosperfiles()->getPerfiles()->nombre . '</td>
    </tr>    
    <tr>
        <td><b>Estado</b></td>
        <td><b>' . $propuesta->getEstados()->nombre . '</b></td>
        <td>Nombre de la propuesta</td>
        <td>' . $propuesta->nombre . '</td>
    </tr>    
    <tr>    
        <td>¿Proyecto de alianza sectorial?</td>
        <td>' . $alianza_sectorial . '</td>
        <td>¿Es la primera vez que la propuesta se presenta al PDAC? </td>
        <td>' . $primera_vez_pdac . '</td>
    </tr>    
    <tr>
        <td>Relación del proyecto con el Plan de Desarrollo de Bogotá</td>
        <td>' . strtr($relacion_plan, $utf8_ansi2) . '</td>
        <td>Línea estratégica del proyecto</td>
        <td>' . strtr($linea_estrategica, $utf8_ansi2). '</td>
    </tr>
    <tr>
        <td>Área del proyecto</td>
        <td colspan="3">' . strtr($area, $utf8_ansi2) . '</td>        
    </tr>     
    <tr>
        <td>Trayectoria de la entidad participante</td>
        <td colspan="3">' . $propuesta->trayectoria_entidad . '</td>        
    </tr>     
    <tr>
        <td>Problema o necesidad</td>
        <td colspan="3">' . $propuesta->problema_necesidad . '</td>        
    </tr>     
    <tr>
        <td>¿Cómo se diagnosticó el problema o necesidad?</td>
        <td colspan="3">' . $propuesta->diagnostico_problema . '</td>        
    </tr>
    <tr>
        <td>Justificación</td>
        <td colspan="3">' . $propuesta->justificacion . '</td>        
    </tr>
    <tr>
        <td>Antecedentes del proyecto</td>
        <td colspan="3">' . $propuesta->atencedente . '</td>        
    </tr>
    <tr>
        <td>Alcance territorial del proyecto</td>
        <td colspan="3">' . $propuesta->alcance_territorial . '</td>        
    </tr>
    <tr>
        <td>Metodología</td>
        <td colspan="3">' . $propuesta->metodologia . '</td>        
    </tr>
    <tr>
        <td>Impacto esperado</td>
        <td colspan="3">' . $propuesta->impacto . '</td>        
    </tr>
    <tr>
        <td>Mecanismos de evaluación cualitativa</td>
        <td colspan="3">' . $propuesta->mecanismos_cualitativa . '</td>        
    </tr>
    <tr>
        <td>Mecanismos de evaluación cuantitativa</td>
        <td colspan="3">' . $propuesta->mecanismos_cuantitativa . '</td>        
    </tr>
    <tr>
        <td>Proyección y reconocimiento nacional o internacional</td>
        <td colspan="3">' . $propuesta->proyeccion_reconocimiento . '</td>        
    </tr>
    <tr>
        <td>Impacto que ha tenido el proyecto</td>
        <td colspan="3">' . $propuesta->impacto_proyecto . '</td>        
    </tr>
    <tr>
        <td>Medio por el cual se enteró de esta convocatoria</td>
        <td>' . strtr($porque_medio, $utf8_ansi2) . '</td>        
        <td>Localidad principal en donde el proyecto desarrollará las acciones</td>
        <td>' . $propuesta->getLocalidades()->nombre . '</td>        
    </tr>
</table>
<h3>Información del participante</h3>
' . $tabla_participante . '
<h3>Documentación administrativa</h3>
<table>    
    <tr>
        <td align="center" bgcolor="#BDBDBD">N°</td>
        <td align="center" bgcolor="#BDBDBD">Requisito</td>    
        <td align="center" bgcolor="#BDBDBD">Nombre del archivo</td>        
    </tr> 
    ' . $html_administrativos . '
</table>
<br/><br/>
<table>    
    <tr>
        <td align="center" bgcolor="#BDBDBD">N°</td>
        <td align="center" bgcolor="#BDBDBD">Requisito</td>    
        <td align="center" bgcolor="#BDBDBD">Link</td>        
    </tr>       
    ' . $html_administrativos_link . '
</table>
<h3>Documentación técnica</h3>
<table>    
    <tr>
        <td align="center" bgcolor="#BDBDBD">N°</td>
        <td align="center" bgcolor="#BDBDBD">Requisito</td>    
        <td align="center" bgcolor="#BDBDBD">Nombre del archivo</td>        
    </tr>     
    ' . $html_tecnicos . '
</table>
<br/><br/>
<table>    
    <tr>
        <td align="center" bgcolor="#BDBDBD">N°</td>
        <td align="center" bgcolor="#BDBDBD">Requisito</td>    
        <td align="center" bgcolor="#BDBDBD">Link</td>        
    </tr> 
    ' . $html_tecnicos_link . '
</table>
';
                    $logger->info('"token":"{token}","user":"{user}","message":"Se genero el reporte de inscripcion de la propuesta (' . $request->getPut('id') . ')', ['user' => "", 'token' => $request->getPut('token')]);
                    $logger->close();

                    echo $html;
                } else {
                    $logger->info('"token":"{token}","user":"{user}","message":"Se genero el reporte de inscripcion de la propuesta (' . $request->getPut('id') . ')', ['user' => "", 'token' => $request->getPut('token')]);
                    $logger->close();

                    echo "<b>No es posible generar el reporte, debido a que su propuesta no esta en estado inscrita.</br>";
                    exit;
                }
            } else {
                //Registro la accion en el log de convocatorias           
                $logger->error('"token":"{token}","user":"{user}","message":"La propuesta (' . $request->getPut('id') . ') no existe en el metodo reporte_propuesta_inscrita', ['user' => "", 'token' => $request->getPut('token')]);
                $logger->close();
                echo "error_propuesta";
            }
        } else {
            //Registro la accion en el log de convocatorias           
            $logger->error('"token":"{token}","user":"{user}","message":"Token caduco en el metodo reporte_propuesta_inscrita al generar el reporte de la propuesta (' . $request->getPut('id') . ')', ['user' => "", 'token' => $request->getPut('token')]);
            $logger->close();
            echo "error_token";
        }
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias           
        $logger->error('"token":"{token}","user":"{user}","message":"Error metodo reporte_propuesta_inscrita al generar el reporte de la propuesta (' . $request->getPut('id') . ')' . $ex->getMessage() . '"', ['user' => "", 'token' => $request->getPut('token')]);
        $logger->close();
        echo "error_metodo";
    }
});

$app->post('/reporte_listado_propuesta_habilitados', function () use ($app, $config, $logger) {

//Instancio los objetos que se van a manejar
    $request = new Request();
    $tokens = new Tokens();

    try {

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPut('token'));

        //Registro la accion en el log de convocatorias
        $logger->info('"token":"{token}","user":"{user}","message":"Ingresa al metodo reporte_listado_propuesta_rechazados_habilitados para generar reporte de listado de inscripcion de la propuesta (' . $request->getPut('id') . ')"', ['user' => '', 'token' => $request->getPut('token')]);

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

            //Consulto la convocatoria
            $convocatoria = Convocatorias::findFirst($request->getPut('id'));
            //Si la convocatoria seleccionada es categoria, debo invertir los nombres la convocatoria con la categoria
            $id_convocatoria=$convocatoria->id;
            $nombre_convocatoria = $convocatoria->nombre;
            $nombre_categoria = "";
            $entidad = $convocatoria->getEntidades()->descripcion;
            $seudonimo = $convocatoria->seudonimo;
            if ($convocatoria->convocatoria_padre_categoria > 0) {                
                $nombre_convocatoria = $convocatoria->getConvocatorias()->nombre;
                $nombre_categoria = $convocatoria->nombre;                
                $entidad = $convocatoria->getConvocatorias()->getEntidades()->descripcion;                
                $seudonimo = $convocatoria->getConvocatorias()->seudonimo;
            }
             
            
            //consulto las propuestas inscritas para crear el listado
            //Inscrita,Anulada,Por Subsanar,Subsanación Recibida,Rechazada,Habilitada,Subsanada
            $conditions = ['convocatoria' => $id_convocatoria, 'active' => true];
            $listado_propuestas_inscritas = Propuestas::find(([
                        'conditions' => 'convocatoria=:convocatoria: AND active=:active: AND estado IN (24)',
                        'bind' => $conditions,
                        'order' => 'codigo ASC',
            ]));
            
            $html_propuestas = "";
            $i=1;
            foreach ($listado_propuestas_inscritas as $propuesta) {
                
                $participante = $propuesta->getParticipantes()->primer_nombre . " " . $propuesta->getParticipantes()->segundo_nombre . " " . $propuesta->getParticipantes()->primer_apellido . " " . $propuesta->getParticipantes()->segundo_apellido;
                if($seudonimo==true)
                {
                    $participante=$propuesta->codigo;
                }
                
                $representante = Participantes::findFirst("participante_padre=".$propuesta->participante." AND representante = true AND active = true");
                $nombre_representante = $representante->primer_nombre . " " . $representante->segundo_nombre . " " . $representante->primer_apellido . " " . $representante->segundo_apellido;
                if($seudonimo==true)
                {
                    $nombre_representante=$propuesta->codigo;
                }
                        
                $html_propuestas = $html_propuestas . "<tr>";
                $html_propuestas = $html_propuestas . '<td>' . $i . '</td>';
                $html_propuestas = $html_propuestas . "<td>" . $propuesta->codigo . "</td>";
                $html_propuestas = $html_propuestas . "<td>" . $participante . "</td>";
                $html_propuestas = $html_propuestas . "<td>" . $nombre_representante . "</td>";
                $html_propuestas = $html_propuestas . "<td>" . $propuesta->nombre. "</td>";                
                $html_propuestas = $html_propuestas . "<td>" . $propuesta->getEstados()->nombre. "</td>";                                
                $html_propuestas = $html_propuestas . "</tr>";
                $i++;
             
            }
                        
            //Validar si existe un participante como persona jurídica, con id usuario innner usuario_perfil
            $user_current = json_decode($token_actual->user_current, true);

            if (isset($convocatoria->id)) {
                
                $html='<table border="1" cellpadding="2" cellspacing="2" nobr="true">
                    <tr>
                        <td colspan="6" align="center">Listado de habilitados</td>
                    </tr>
                    <tr>
                        <td colspan="6" align="center">'.$entidad.'</td>
                    </tr>
                    <tr>
                        <td colspan="6" align="center"> Fecha de corte ' . date("Y-m-d H:i:s") . '</td>
                    </tr>
                    <tr>
                        <td>Convocatoria</td>
                        <td colspan="3">'.$nombre_convocatoria.'</td>
                        <td>Categoría</td>
                        <td>'.$nombre_categoria.'</td>
                    </tr>                    
                    <tr style="background-color:#BDBDBD;color:#OOOOOO;">
                        <td align="center"></td>
                        <td align="center">Código</td>
                        <td align="center">Participante</td>
                        <td align="center">Representante</td>
                        <td align="center">Nombre de la propuesta</td>
                        <td align="center">Estado</td>                        
                    </tr> 
                    ' . $html_propuestas . '
                </table>';
                
                $logger->info('"token":"{token}","user":"{user}","message":"Se genero el reporte de inscripcion de la propuesta (' . $request->getPut('id') . ')', ['user' => $user_current["username"], 'token' => $request->getPut('token')]);
                $logger->close();
                echo $html;
                    
            } else {
                //Registro la accion en el log de convocatorias           
                $logger->error('"token":"{token}","user":"{user}","message":"La propuesta (' . $request->getPut('id') . ') no existe en el metodo reporte_propuesta_inscrita', ['user' => "", 'token' => $request->getPut('token')]);
                $logger->close();
                echo "error_propuesta";
            }
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

$app->post('/reporte_listado_propuesta_rechazados_habilitados', function () use ($app, $config, $logger) {

//Instancio los objetos que se van a manejar
    $request = new Request();
    $tokens = new Tokens();

    try {

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPut('token'));

        //Registro la accion en el log de convocatorias
        $logger->info('"token":"{token}","user":"{user}","message":"Ingresa al metodo reporte_listado_propuesta_rechazados_habilitados para generar reporte de listado de inscripcion de la propuesta (' . $request->getPut('id') . ')"', ['user' => '', 'token' => $request->getPut('token')]);

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

            //Consulto la convocatoria
            $convocatoria = Convocatorias::findFirst($request->getPut('id'));
            //Si la convocatoria seleccionada es categoria, debo invertir los nombres la convocatoria con la categoria
            $id_convocatoria=$convocatoria->id;
            $nombre_convocatoria = $convocatoria->nombre;
            $nombre_categoria = "";
            $entidad = $convocatoria->getEntidades()->descripcion;
            $seudonimo = $convocatoria->seudonimo;
            if ($convocatoria->convocatoria_padre_categoria > 0) {                
                $nombre_convocatoria = $convocatoria->getConvocatorias()->nombre;
                $nombre_categoria = $convocatoria->nombre;                
                $entidad = $convocatoria->getConvocatorias()->getEntidades()->descripcion;                
                $seudonimo = $convocatoria->getConvocatorias()->seudonimo;
            }
             
            
            //consulto las propuestas inscritas para crear el listado
            //Rechazada,Habilitada
            $conditions = ['convocatoria' => $id_convocatoria, 'active' => true];
            $listado_propuestas_inscritas = Propuestas::find(([
                        'conditions' => 'convocatoria=:convocatoria: AND active=:active: AND estado IN (23,24)',
                        'bind' => $conditions,
                        'order' => 'codigo ASC',
            ]));
            
            $html_propuestas = "";
            $i=1;            
            foreach ($listado_propuestas_inscritas as $propuesta) {
                
                $participante = $propuesta->getParticipantes()->primer_nombre . " " . $propuesta->getParticipantes()->segundo_nombre . " " . $propuesta->getParticipantes()->primer_apellido . " " . $propuesta->getParticipantes()->segundo_apellido;
                if($seudonimo==true)
                {
                    $participante=$propuesta->codigo;
                }
                
                $representante = Participantes::findFirst("participante_padre=".$propuesta->participante." AND representante = true AND active = true");
                $nombre_representante = $representante->primer_nombre . " " . $representante->segundo_nombre . " " . $representante->primer_apellido . " " . $representante->segundo_apellido;
                if($seudonimo==true)
                {
                    $nombre_representante=$propuesta->codigo;
                }
                
                $text_observacion="";
                
                if($propuesta->estado==23)
                {
                    $observaciones = $propuesta->getPropuestasverificaciones([
                                "observacion <> '' AND verificacion IN (1,2) AND estado IN (26,30)"                                
                    ]);

                    foreach ($observaciones as $observacion) {
                        $text_observacion=$observacion->observacion." , ".$text_observacion;
                    }

                    $text_observacion = substr($text_observacion, 0, -2);
                }
                        
                $html_propuestas = $html_propuestas . "<tr>";
                $html_propuestas = $html_propuestas . '<td width="30">' . $i . '</td>';
                $html_propuestas = $html_propuestas . '<td width="60">' . $propuesta->codigo . '</td>';
                $html_propuestas = $html_propuestas . "<td>" . $participante . "</td>";
                $html_propuestas = $html_propuestas . "<td>" . $nombre_representante . "</td>";
                $html_propuestas = $html_propuestas . "<td>" . $propuesta->nombre. "</td>";                
                $html_propuestas = $html_propuestas . '<td width="80">' . $propuesta->getEstados()->nombre. '</td>';                
                $html_propuestas = $html_propuestas . '<td width="361">' . $text_observacion. '</td>';                                
                $html_propuestas = $html_propuestas . "</tr>";
                
                $i++;
             
            }
                        
            //Validar si existe un participante como persona jurídica, con id usuario innner usuario_perfil
            $user_current = json_decode($token_actual->user_current, true);

            if (isset($convocatoria->id)) {
                
                $html='<table border="1" cellpadding="2" cellspacing="2" nobr="true">
                    <tr>
                        <td colspan="7" align="center">Listado de habilitados y rechazados</td>
                    </tr>
                    <tr>
                        <td colspan="7" align="center">'.$entidad.'</td>
                    </tr>
                    <tr>
                        <td colspan="7" align="center"> Fecha de corte ' . date("Y-m-d H:i:s") . '</td>
                    </tr>
                    <tr>
                        <td>Convocatoria</td>
                        <td colspan="3">'.$nombre_convocatoria.'</td>
                        <td>Categoría</td>
                        <td colspan="2">'.$nombre_categoria.'</td>
                    </tr>                    
                    <tr style="background-color:#BDBDBD;color:#OOOOOO;">
                    
                        <td align="center" width="30"></td>
                        <td align="center" width="60">Código</td>
                        <td align="center">Participante</td>
                        <td align="center">Representante</td>
                        <td align="center">Nombre de la propuesta</td>
                        <td align="center" width="80">Estado</td>
                        <td align="center" width="361">Observaciones</td>
                    </tr> 
                    ' . $html_propuestas . '
                </table>';
                
                $logger->info('"token":"{token}","user":"{user}","message":"Se genero el reporte de inscripcion de la propuesta (' . $request->getPut('id') . ')', ['user' => $user_current["username"], 'token' => $request->getPut('token')]);
                $logger->close();
                echo $html;
                    
            } else {
                //Registro la accion en el log de convocatorias           
                $logger->error('"token":"{token}","user":"{user}","message":"La propuesta (' . $request->getPut('id') . ') no existe en el metodo reporte_propuesta_inscrita', ['user' => "", 'token' => $request->getPut('token')]);
                $logger->close();
                echo "error_propuesta";
            }
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

$app->post('/reporte_listado_propuesta_rechazados_subsanar', function () use ($app, $config, $logger) {

//Instancio los objetos que se van a manejar
    $request = new Request();
    $tokens = new Tokens();

    try {

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPut('token'));

        //Registro la accion en el log de convocatorias
        $logger->info('"token":"{token}","user":"{user}","message":"Ingresa al metodo reporte_listado_propuesta_rechazados_subsanar para generar reporte de listado de inscripcion de la propuesta (' . $request->getPut('id') . ')"', ['user' => '', 'token' => $request->getPut('token')]);

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

            //Consulto la convocatoria
            $convocatoria = Convocatorias::findFirst($request->getPut('id'));
            //Si la convocatoria seleccionada es categoria, debo invertir los nombres la convocatoria con la categoria
            $id_convocatoria=$convocatoria->id;
            $nombre_convocatoria = $convocatoria->nombre;
            $nombre_categoria = "";
            $entidad = $convocatoria->getEntidades()->descripcion;
            $seudonimo = $convocatoria->seudonimo;
            if ($convocatoria->convocatoria_padre_categoria > 0) {                
                $nombre_convocatoria = $convocatoria->getConvocatorias()->nombre;
                $nombre_categoria = $convocatoria->nombre;                
                $entidad = $convocatoria->getConvocatorias()->getEntidades()->descripcion;                
                $seudonimo = $convocatoria->getConvocatorias()->seudonimo;
            }
             
            
            //consulto las propuestas inscritas para crear el listado
            //Por Subsanar,Subsanación Recibida,Rechazada,Habilitada,Subsanada
            $conditions = ['convocatoria' => $id_convocatoria, 'active' => true];
            $listado_propuestas_inscritas = Propuestas::find(([
                        'conditions' => 'convocatoria=:convocatoria: AND active=:active: AND estado IN (21,22,23,24,31)',
                        'bind' => $conditions,
                        'order' => 'codigo ASC',
            ]));
            
            $html_propuestas = "";
            $i=1;
            foreach ($listado_propuestas_inscritas as $propuesta) {
                
                $participante = $propuesta->getParticipantes()->primer_nombre . " " . $propuesta->getParticipantes()->segundo_nombre . " " . $propuesta->getParticipantes()->primer_apellido . " " . $propuesta->getParticipantes()->segundo_apellido;
                if($seudonimo==true)
                {
                    $participante=$propuesta->codigo;
                }
                
                $representante = Participantes::findFirst("participante_padre=".$propuesta->participante." AND representante = true AND active = true");
                $nombre_representante = $representante->primer_nombre . " " . $representante->segundo_nombre . " " . $representante->primer_apellido . " " . $representante->segundo_apellido;
                if($seudonimo==true)
                {
                    $nombre_representante=$propuesta->codigo;
                }
                
                //Traemos solo los comentarios de rechazada
                if($propuesta->estado==23)
                {
                    $verificacion_estado=" AND estado IN (26)";
                }
                
                //Traemos solo los comentarios de por subsanar
                if($propuesta->estado==21)
                {
                    $verificacion_estado=" AND estado IN (27)";
                }
                                
                $observaciones = $propuesta->getPropuestasverificaciones([
                                "observacion <> '' AND verificacion=1".$verificacion_estado
                    ]);
                $text_observacion="";
                foreach ($observaciones as $observacion) {
                    $text_observacion=$observacion->observacion." , ".$text_observacion;
                }
                
                $text_observacion = substr($text_observacion, 0, -2);
                
                $html_propuestas = $html_propuestas . "<tr>";
                $html_propuestas = $html_propuestas . '<td width="44">' . $i . '</td>';
                $html_propuestas = $html_propuestas . '<td  width="60">' . $propuesta->codigo . '</td>';
                $html_propuestas = $html_propuestas . "<td>" . $participante . "</td>";
                $html_propuestas = $html_propuestas . "<td>" . $nombre_representante . "</td>";
                $html_propuestas = $html_propuestas . "<td>" . $propuesta->nombre. "</td>";                
                $html_propuestas = $html_propuestas . '<td width="80">' . $propuesta->getEstados()->nombre. '</td>';                
                $html_propuestas = $html_propuestas . '<td width="347">' . $text_observacion. '</td>';                
                $html_propuestas = $html_propuestas . "</tr>";
                $i++;
             
            }
                        
            //Validar si existe un participante como persona jurídica, con id usuario innner usuario_perfil
            $user_current = json_decode($token_actual->user_current, true);

            if (isset($convocatoria->id)) {
                
                $html='<table border="1" cellpadding="2" cellspacing="2" nobr="true">
                    <tr>
                        <td colspan="7" align="center">Listado de habilitados, rechazados y documentos por subsanar</td>
                    </tr>
                    <tr>
                        <td colspan="7" align="center">'.$entidad.'</td>
                    </tr>
                    <tr>
                        <td colspan="7" align="center"> Fecha de corte ' . date("Y-m-d H:i:s") . '</td>
                    </tr>
                    <tr>
                        <td>Convocatoria</td>
                        <td colspan="3">'.$nombre_convocatoria.'</td>
                        <td>Categoría</td>
                        <td colspan="2">'.$nombre_categoria.'</td>
                    </tr>                    
                    <tr style="background-color:#BDBDBD;color:#OOOOOO;">
                        <td align="center" width="44"></td>
                        <td align="center" width="60">Código</td>
                        <td align="center">Participante</td>
                        <td align="center">Representante</td>
                        <td align="center">Nombre de la propuesta</td>
                        <td align="center" width="80">Estado</td>
                        <td align="center" width="347">Observaciones</td>
                    </tr> 
                    ' . $html_propuestas . '
                </table>';
                
                $logger->info('"token":"{token}","user":"{user}","message":"Se genero el reporte de inscripcion de la propuesta (' . $request->getPut('id') . ')', ['user' => $user_current["username"], 'token' => $request->getPut('token')]);
                $logger->close();
                echo $html;
                    
            } else {
                //Registro la accion en el log de convocatorias           
                $logger->error('"token":"{token}","user":"{user}","message":"La propuesta (' . $request->getPut('id') . ') no existe en el metodo reporte_propuesta_inscrita', ['user' => "", 'token' => $request->getPut('token')]);
                $logger->close();
                echo "error_propuesta";
            }
        } else {
            //Registro la accion en el log de convocatorias           
            $logger->error('"token":"{token}","user":"{user}","message":"Token caduco en el metodo reporte_listado_propuesta_rechazados_subsanar al generar el reporte listado de la propuesta (' . $request->getPut('id') . ')', ['user' => "", 'token' => $request->getPut('token')]);
            $logger->close();
            echo "error_token";
        }
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias           
        $logger->error('"token":"{token}","user":"{user}","message":"Error metodo reporte_listado_propuesta_rechazados_subsanar al generar el reporte listado de la propuesta (' . $request->getPut('id') . ')' . $ex->getMessage() . '"', ['user' => "", 'token' => $request->getPut('token')]);
        $logger->close();
        echo "error_metodo";
    }
});

$app->post('/reporte_listado_inscrita', function () use ($app, $config, $logger) {

//Instancio los objetos que se van a manejar
    $request = new Request();
    $tokens = new Tokens();

    try {

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPut('token'));

        //Registro la accion en el log de convocatorias
        $logger->info('"token":"{token}","user":"{user}","message":"Ingresa al metodo reporte_listado_inscrita para generar reporte de listado de inscripcion de la propuesta (' . $request->getPut('id') . ')"', ['user' => '', 'token' => $request->getPut('token')]);

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

            //Consulto la convocatoria
            $convocatoria = Convocatorias::findFirst($request->getPut('id'));
            //Si la convocatoria seleccionada es categoria, debo invertir los nombres la convocatoria con la categoria
            $id_convocatoria=$convocatoria->id;
            $nombre_convocatoria = $convocatoria->nombre;
            $nombre_categoria = "";
            $entidad = $convocatoria->getEntidades()->descripcion;
            $seudonimo = $convocatoria->seudonimo;
            if ($convocatoria->convocatoria_padre_categoria > 0) {                
                $nombre_convocatoria = $convocatoria->getConvocatorias()->nombre;
                $nombre_categoria = $convocatoria->nombre;                
                $entidad = $convocatoria->getConvocatorias()->getEntidades()->descripcion;                
                $seudonimo = $convocatoria->getConvocatorias()->seudonimo;
            }
             
            
            //consulto las propuestas inscritas para crear el listado            
            //Inscrita,Por Subsanar,Subsanación Recibida,Rechazada,Habilitada,Subsanada
            $conditions = ['convocatoria' => $id_convocatoria, 'active' => true];
            $listado_propuestas_inscritas = Propuestas::find(([
                        'conditions' => 'convocatoria=:convocatoria: AND active=:active: AND estado IN (8,21,22,23,24,31)',
                        'bind' => $conditions,
                        'order' => 'codigo ASC',
            ]));
            
            $html_propuestas = "";
            $i=1;
            foreach ($listado_propuestas_inscritas as $propuesta) {
                
                $participante = $propuesta->getParticipantes()->primer_nombre . " " . $propuesta->getParticipantes()->segundo_nombre . " " . $propuesta->getParticipantes()->primer_apellido . " " . $propuesta->getParticipantes()->segundo_apellido;
                if($seudonimo==true)
                {
                    $participante=$propuesta->codigo;
                }
                
                $representante = Participantes::findFirst("participante_padre=".$propuesta->participante." AND representante = true AND active = true");
                $nombre_representante = $representante->primer_nombre . " " . $representante->segundo_nombre . " " . $representante->primer_apellido . " " . $representante->segundo_apellido;
                if($seudonimo==true)
                {
                    $nombre_representante=$propuesta->codigo;
                }
                        
                $html_propuestas = $html_propuestas . "<tr>";
                $html_propuestas = $html_propuestas . "<td width='30'>" . $i . "</td>";
                $html_propuestas = $html_propuestas . "<td>" . $propuesta->codigo . "</td>";
                $html_propuestas = $html_propuestas . "<td>" . $participante . "</td>";
                $html_propuestas = $html_propuestas . "<td>" . $nombre_representante . "</td>";
                $html_propuestas = $html_propuestas . "<td width='343'>" . $propuesta->nombre. "</td>";                
                $html_propuestas = $html_propuestas . "</tr>";
                $i++;
             
            }
                        
            //Validar si existe un participante como persona jurídica, con id usuario innner usuario_perfil
            $user_current = json_decode($token_actual->user_current, true);

            if (isset($convocatoria->id)) {
                
                $html='<table border="1" cellpadding="2" cellspacing="2" nobr="true">
                    <tr>
                        <td colspan="5" align="center">Listado de participantes inscritos</td>
                    </tr>
                    <tr>
                        <td colspan="5" align="center">'.$entidad.'</td>
                    </tr>
                    <tr>
                        <td colspan="5" align="center"> Fecha de corte ' . date("Y-m-d H:i:s") . '</td>
                    </tr>
                    <tr>
                        <td>Convocatoria</td>
                        <td colspan="2">'.$nombre_convocatoria.'</td>
                        <td>Categoría</td>
                        <td>'.$nombre_categoria.'</td>
                    </tr>                                    
                    <tr style="background-color:#BDBDBD;color:#OOOOOO;">
                        <td align="center" width="30"></td>
                        <td align="center">Código</td>
                        <td align="center">Participante</td>
                        <td align="center">Representante</td>
                        <td align="center" width="343">Nombre de la propuesta</td>
                    </tr> 
                    ' . $html_propuestas . '
                </table>';
                
                $logger->info('"token":"{token}","user":"{user}","message":"Se genero el reporte de inscripcion de la propuesta (' . $request->getPut('id') . ')', ['user' => $user_current["username"], 'token' => $request->getPut('token')]);
                $logger->close();
                echo $html;
                    
            } else {
                //Registro la accion en el log de convocatorias           
                $logger->error('"token":"{token}","user":"{user}","message":"La propuesta (' . $request->getPut('id') . ') no existe en el metodo reporte_propuesta_inscrita', ['user' => "", 'token' => $request->getPut('token')]);
                $logger->close();
                echo "error_propuesta";
            }
        } else {
            //Registro la accion en el log de convocatorias           
            $logger->error('"token":"{token}","user":"{user}","message":"Token caduco en el metodo reporte_listado_inscrita al generar el reporte listado de la propuesta (' . $request->getPut('id') . ')', ['user' => "", 'token' => $request->getPut('token')]);
            $logger->close();
            echo "error_token";
        }
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias           
        $logger->error('"token":"{token}","user":"{user}","message":"Error metodo reporte_listado_inscrita al generar el reporte listado de la propuesta (' . $request->getPut('id') . ')' . $ex->getMessage() . '"', ['user' => "", 'token' => $request->getPut('token')]);
        $logger->close();
        echo "error_metodo";
    }
});

$app->post('/reporte_listado_pre_inscrita', function () use ($app, $config, $logger) {

//Instancio los objetos que se van a manejar
    $request = new Request();
    $tokens = new Tokens();

    try {

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPut('token'));

        //Registro la accion en el log de convocatorias
        $logger->info('"token":"{token}","user":"{user}","message":"Ingresa al metodo reporte_listado_pre_inscrita para generar reporte de listado de inscripcion de la propuesta (' . $request->getPut('id') . ')"', ['user' => '', 'token' => $request->getPut('token')]);

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

            //Consulto la convocatoria
            $convocatoria = Convocatorias::findFirst($request->getPut('id'));
            //Si la convocatoria seleccionada es categoria, debo invertir los nombres la convocatoria con la categoria
            $id_convocatoria=$convocatoria->id;
            $nombre_convocatoria = $convocatoria->nombre;
            $nombre_categoria = "";
            $entidad = $convocatoria->getEntidades()->descripcion;
            $seudonimo = $convocatoria->seudonimo;
            if ($convocatoria->convocatoria_padre_categoria > 0) {                
                $nombre_convocatoria = $convocatoria->getConvocatorias()->nombre;
                $nombre_categoria = $convocatoria->nombre;                
                $entidad = $convocatoria->getConvocatorias()->getEntidades()->descripcion;                
                $seudonimo = $convocatoria->getConvocatorias()->seudonimo;
            }
             
            
            //consulto las propuestas inscritas para crear el listado
            //Pre Inscrita
            $conditions = ['convocatoria' => $id_convocatoria, 'active' => true];
            $listado_propuestas_inscritas = Propuestas::find(([
                        'conditions' => 'convocatoria=:convocatoria: AND active=:active: AND estado IN (7)',
                        'bind' => $conditions,
                        'order' => 'fecha_creacion ASC',
            ]));
            
            $html_propuestas = "";
            $i=1;
            foreach ($listado_propuestas_inscritas as $propuesta) {
                
                $participante = $propuesta->getParticipantes()->primer_nombre . " " . $propuesta->getParticipantes()->segundo_nombre . " " . $propuesta->getParticipantes()->primer_apellido . " " . $propuesta->getParticipantes()->segundo_apellido;
                if($seudonimo==true)
                {
                    $participante=$propuesta->codigo;
                }
                
                $representante = Participantes::findFirst("participante_padre=".$propuesta->participante." AND representante = true AND active = true");
                $creado_por = Usuarios::findFirst("id=".$propuesta->creado_por."");
                $nombre_representante = $representante->primer_nombre . " " . $representante->segundo_nombre . " " . $representante->primer_apellido . " " . $representante->segundo_apellido;
                if($seudonimo==true)
                {
                    $nombre_representante=$propuesta->codigo;
                }
                        
                $html_propuestas = $html_propuestas . "<tr>";
                $html_propuestas = $html_propuestas . "<td>" . $i . "</td>";
                $html_propuestas = $html_propuestas . "<td>" . $creado_por->username . "</td>";
                $html_propuestas = $html_propuestas . "<td>" . $participante . "</td>";
                $html_propuestas = $html_propuestas . "<td>" . $nombre_representante . "</td>";
                $html_propuestas = $html_propuestas . "<td>" . $propuesta->nombre. "</td>";                
                $html_propuestas = $html_propuestas . "</tr>";
                $i++;
            }
                        
            //Validar si existe un participante como persona jurídica, con id usuario innner usuario_perfil
            $user_current = json_decode($token_actual->user_current, true);

            if (isset($convocatoria->id)) {
                
                $html='<table border="1" cellpadding="2" cellspacing="2" nobr="true">
                    <tr>
                        <td colspan="5" align="center">Listado de participantes pre-inscritos</td>
                    </tr>
                    <tr>
                        <td colspan="5" align="center">'.$entidad.'</td>
                    </tr>
                    <tr>
                        <td colspan="5" align="center"> Fecha de corte ' . date("Y-m-d H:i:s") . '</td>
                    </tr>
                    <tr>
                        <td>Convocatoria</td>
                        <td colspan="2">'.$nombre_convocatoria.'</td>
                        <td>Categoría</td>
                        <td>'.$nombre_categoria.'</td>
                    </tr>                    
                    <tr style="background-color:#BDBDBD;color:#OOOOOO;">
                        <td align="center" width="30"></td>
                        <td align="center" width="200">Usuario</td>
                        <td align="center" width="250">Participante</td>
                        <td align="center" width="200">Representante</td>
                        <td align="center" width="255">Nombre de la propuesta</td>
                    </tr> 
                    ' . $html_propuestas . '
                </table>';
                
                $logger->info('"token":"{token}","user":"{user}","message":"Se genero el reporte de inscripcion de la propuesta (' . $request->getPut('id') . ')', ['user' => $user_current["username"], 'token' => $request->getPut('token')]);
                $logger->close();
                echo $html;
                    
            } else {
                //Registro la accion en el log de convocatorias           
                $logger->error('"token":"{token}","user":"{user}","message":"La propuesta (' . $request->getPut('id') . ') no existe en el metodo reporte_propuesta_inscrita', ['user' => "", 'token' => $request->getPut('token')]);
                $logger->close();
                echo "error_propuesta";
            }
        } else {
            //Registro la accion en el log de convocatorias           
            $logger->error('"token":"{token}","user":"{user}","message":"Token caduco en el metodo reporte_listado_pre_inscrita al generar el reporte listado de la propuesta (' . $request->getPut('id') . ')', ['user' => "", 'token' => $request->getPut('token')]);
            $logger->close();
            echo "error_token";
        }
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias           
        $logger->error('"token":"{token}","user":"{user}","message":"Error metodo reporte_listado_pre_inscrita al generar el reporte listado de la propuesta (' . $request->getPut('id') . ')' . $ex->getMessage() . '"', ['user' => "", 'token' => $request->getPut('token')]);
        $logger->close();
        echo "error_metodo";
    }
});

$app->post('/reporte_propuesta_subsanacion', function () use ($app, $config, $logger) {

//Instancio los objetos que se van a manejar
    $request = new Request();
    $tokens = new Tokens();

    try {

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPut('token'));

        //Registro la accion en el log de convocatorias
        $logger->info('"token":"{token}","user":"{user}","message":"Ingresa al metodo reporte_propuesta_inscrita para generar reporte de inscripcion de la propuesta (' . $request->getPut('id') . ')"', ['user' => '', 'token' => $request->getPut('token')]);

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

            $propuesta = Propuestas::findFirst($request->getPut('id'));

            if (isset($propuesta->id)) {
                
                //Valido para que genere el reporte solo al momento de 
                //clic en generar reporte al inscribir la propuesta por primera vez
                $estado = $propuesta->estado;
                $titulo_reporte="CERTIFICADO DE SUBSANACIÓN";
                $generar = $request->getPut('vi');
                $parrafo_1="La subsanación ha sido realizada correctamente, recuerde que después del periodo de subsanación, su propuesta pasa a verificación de los requisitos subsanados.";
                if($generar==1)
                {
                    $estado=8;
                    $titulo_reporte="CERTIFICADO DE PRE-SUBSANACIÓN";
                    $parrafo_1="La subsanación no ha sido confirmada, recuerde que después del periodo de subsanación, su propuesta pasa a verificación de los requisitos subsanados.";
                }
                
                if ($estado <> 7 && $estado <> 20) {
                    
                    $array_administrativos = array();                    
                    foreach ($propuesta->Propuestasdocumentos as $propuestadocumento) {
                        //if ($propuestadocumento->getConvocatoriasdocumentos()->getRequisitos()->tipo_requisito == "Administrativos" AND $propuestadocumento->cargue_subsanacion == true) {
                        if ($propuestadocumento->cargue_subsanacion == true AND $propuestadocumento->active == true) {
                            $array_administrativos[$propuestadocumento->id]["requisito"] = $propuestadocumento->getConvocatoriasdocumentos()->getRequisitos()->nombre;
                            $array_administrativos[$propuestadocumento->id]["nombre"] = $propuestadocumento->nombre;
                        }                        
                    }

                    $array_administrativos_link = array();                    
                    foreach ($propuesta->Propuestaslinks as $propuestalink) {
                        //if ($propuestalink->getConvocatoriasdocumentos()->getRequisitos()->tipo_requisito == "Administrativos" AND $propuestalink->cargue_subsanacion == true) {
                        if ($propuestalink->cargue_subsanacion == true AND $propuestalink->active == true) {
                            $array_administrativos_link[$propuestalink->id]["requisito"] = $propuestalink->getConvocatoriasdocumentos()->getRequisitos()->nombre;
                            $array_administrativos_link[$propuestalink->id]["link"] = $propuestalink->link;
                        }                        
                    }

                    $html_administrativos = "";
                    $i = 1;
                    foreach ($array_administrativos as $key => $val) {
                        $html_administrativos = $html_administrativos . "<tr>";
                        $html_administrativos = $html_administrativos . "<td>" . $i . "</td>";
                        $html_administrativos = $html_administrativos . "<td>" . $val["requisito"] . "</td>";
                        $html_administrativos = $html_administrativos . "<td>" . $val["nombre"] . "</td>";
                        $html_administrativos = $html_administrativos . "</tr>";
                        $i++;
                    }

                    $html_administrativos_link = "";
                    $i = 1;
                    foreach ($array_administrativos_link as $key => $val) {
                        $html_administrativos_link = $html_administrativos_link . "<tr>";
                        $html_administrativos_link = $html_administrativos_link . "<td>" . $i . "</td>";
                        $html_administrativos_link = $html_administrativos_link . "<td>" . $val["requisito"] . "</td>";
                        $html_administrativos_link = $html_administrativos_link . "<td>" . $val["link"] . "</td>";
                        $html_administrativos_link = $html_administrativos_link . "</tr>";
                        $i++;
                    }

                    $bogota = ($propuesta->bogota) ? "Si" : "No";

                    //Si la convocatoria seleccionada es categoria, debo invertir los nombres la convocatoria con la categoria
                    $nombre_convocatoria = $propuesta->getConvocatorias()->nombre;
                    $nombre_categoria = "";
                    if ($propuesta->getConvocatorias()->convocatoria_padre_categoria > 0) {
                        $nombre_convocatoria = $propuesta->getConvocatorias()->getConvocatorias()->nombre;
                        $nombre_categoria = $propuesta->getConvocatorias()->nombre;
                    }

                    $participante = $propuesta->getParticipantes()->primer_nombre . " " . $propuesta->getParticipantes()->segundo_nombre . " " . $propuesta->getParticipantes()->primer_apellido . " " . $propuesta->getParticipantes()->segundo_apellido;

                    //Creo la tabla deacuerdo al tipo de participante
                    //Participante natural
                    if ($propuesta->getParticipantes()->getUsuariosperfiles()->getPerfiles()->id == 6) {
                        $tabla_participante = '<table>
    <tr>
        <td>Tipo de documento de identificación</td>
        <td>' . $propuesta->getParticipantes()->getTiposdocumentos()->descripcion . '</td>    
        <td>Número de documento de identificación</td>
        <td>' . $propuesta->getParticipantes()->numero_documento . '</td>
    </tr>    
    <tr>
        <td>Primer nombre</td>
        <td>' . $propuesta->getParticipantes()->primer_nombre . '</td>
        <td>Segundo nombre</td>
        <td>' . $propuesta->getParticipantes()->segundo_nombre . '</td>
    </tr>    
    <tr>
        <td>Primer apellido</td>
        <td>' . $propuesta->getParticipantes()->primer_apellido . '</td>
        <td>Segundo apellido</td>
        <td>' . $propuesta->getParticipantes()->segundo_apellido . '</td>
    </tr>    
    <tr>
        <td>Sexo</td>
        <td>' . $propuesta->getParticipantes()->getSexos()->nombre . '</td>
        <td>Orientación Sexual</td>
        <td>' . $propuesta->getParticipantes()->getOrientacionessexuales()->nombre . '</td>
    </tr>    
    <tr>
        <td>Identidad de género</td>
        <td>' . $propuesta->getParticipantes()->getIdentidadesgeneros()->nombre . '</td>
        <td>Grupo étnico</td>
        <td>' . $propuesta->getParticipantes()->getGruposetnicos()->nombre . '</td>
    </tr>    
    <tr>
        <td>Fecha de nacimiento</td>
        <td>' . $propuesta->getParticipantes()->fecha_nacimiento . '</td>
        <td>Ciudad de nacimiento</td>
        <td>' . $propuesta->getParticipantes()->getCiudadesnacimiento()->nombre . '</td>
    </tr>    
    <tr>
        <td>Ciudad de residencia</td>
        <td>' . $propuesta->getParticipantes()->getCiudadesresidencia()->nombre . '</td>
        <td>Barrio residencia</td>
        <td>' . $propuesta->getParticipantes()->getBarriosresidencia()->nombre . '</td>
    </tr>    
    <tr>
        <td>Dirección de residencia</td>
        <td>' . $propuesta->getParticipantes()->direccion_residencia . '</td>
        <td>Dirección correspondencia</td>
        <td>' . $propuesta->getParticipantes()->direccion_correspondencia . '</td>
    </tr>    
    <tr>
        <td>Estrato</td>
        <td>' . $propuesta->getParticipantes()->estrato . '</td>
        <td>Teléfono fijo</td>
        <td>' . $propuesta->getParticipantes()->numero_telefono . '</td>
    </tr>    
    <tr>
        <td>Número de celular personal</td>
        <td>' . $propuesta->getParticipantes()->numero_celular . '</td>
        <td>Correo electrónico</td>
        <td>' . $propuesta->getParticipantes()->correo_electronico . '</td>
    </tr>    
    <tr>
        <td>Redes sociales</td>
        <td>' . $propuesta->getParticipantes()->facebook . '</td>
        <td>Página web, vínculo o blog</td>
        <td>' . $propuesta->getParticipantes()->links . '</td>
    </tr>           
</table>';
                    }
                    //Participante juridico
                    if ($propuesta->getParticipantes()->getUsuariosperfiles()->getPerfiles()->id == 7) {
                        $conditions = ['id' => $propuesta->participante, 'participante_padre' => $propuesta->participante, 'tipo' => 'Junta', 'active' => true];

                        $cuenta_sede = ($propuesta->getParticipantes()->cuenta_sede) ? 'Sí' : 'No';
                        $tabla_participante = '<table>
    <tr>
        <td>Tipo de documento de identificación</td>
        <td>' . $propuesta->getParticipantes()->getTiposdocumentos()->descripcion . '</td>    
        <td>Número de Nit</td>
        <td>' . $propuesta->getParticipantes()->numero_documento . '</td>
    </tr>    
    <tr>
        <td>DV</td>
        <td>' . $propuesta->getParticipantes()->dv . '</td>
        <td>Razón Social</td>
        <td>' . $propuesta->getParticipantes()->primer_nombre . '</td>
    </tr>       
    <tr>
        <td>Municipio</td>
        <td>' . $propuesta->getParticipantes()->getCiudadesresidencia()->nombre . '</td>
        <td>Barrio</td>
        <td>' . $propuesta->getParticipantes()->getBarriosresidencia()->nombre . '</td>
    </tr>    
    <tr>
        <td>Estrato</td>
        <td>' . $propuesta->getParticipantes()->estrato . '</td>
        <td>Dirección</td>
        <td>' . $propuesta->getParticipantes()->direccion_residencia . '</td>        
    </tr>    
    <tr>
        <td>Teléfono fijo</td>
        <td>' . $propuesta->getParticipantes()->numero_telefono . '</td>
        <td>Número de celular</td>
        <td>' . $propuesta->getParticipantes()->numero_celular . '</td>
    </tr>    
    <tr>
        <td>Objeto Social</td>
        <td>' . $propuesta->getParticipantes()->objeto_social . '</td>
        <td>Fecha de Constitución</td>
        <td>' . $propuesta->getParticipantes()->fecha_nacimiento . '</td>
    </tr>    
    <tr>
        <td>Correo electrónico</td>
        <td>' . $propuesta->getParticipantes()->correo_electronico . '</td>
        <td>¿Cuenta con sede?</td>
        <td>' . $cuenta_sede . '</td>
    </tr>    
    <tr>
        <td>Tipo de sede</td>
        <td>' . $propuesta->getParticipantes()->tipo_sede . '</td>
        <td>Redes sociales</td>
        <td>' . $propuesta->getParticipantes()->facebook . '</td>        
    </tr> 
    <tr>
        <td>Página web, vínculo o blog</td>
        <td>' . $propuesta->getParticipantes()->links . '</td>
    </tr> 
</table>
';
                    }
                    //Participante agrupacion
                    if ($propuesta->getParticipantes()->getUsuariosperfiles()->getPerfiles()->id == 8) {

                        $conditions = ['id' => $propuesta->participante, 'participante_padre' => $propuesta->participante, 'tipo' => 'Integrante', 'active' => true];

                        $tabla_participante = '<table>
    <tr>
        <td>Nombre de la agrupación</td>
        <td>' . $propuesta->getParticipantes()->primer_nombre . '</td>
        <td>Correo electrónico de la entidad</td>
        <td>' . $propuesta->getParticipantes()->correo_electronico . '</td>
    </tr>    
    <tr>        
        <td>Redes sociales</td>
        <td>' . $propuesta->getParticipantes()->facebook . '</td>            
        <td>Página web, vínculo o blog</td>
        <td>' . $propuesta->getParticipantes()->links . '</td>
    </tr> 
</table>
';
                    }


                    $html = '<!-- EXAMPLE OF CSS STYLE -->
<style>
        table {
		font-size: 10pt;	
	}
        
	td {
		border: 1px solid #E3E3E3;	
                background-color: #ffffee;
	}
</style>
<h2  style="text-align:center;">'.$titulo_reporte.'</h2>
<h3>Información de la propuesta</h3>        
<p>'.$parrafo_1.'</p>
<table>
    <tr>
        <td colspan="2"><b>Código</b></td>
        <td colspan="2"><b>' . $propuesta->codigo . '</b></td>            
    </tr>    
    <tr>
        <td>Nombre de la convocatoria</td>
        <td>' . $nombre_convocatoria . '</td>    
        <td>Categoría de la convocatoria</td>
        <td>' . $nombre_categoria . '</td>
    </tr>    
    <tr>
        <td>Nombre del participante</td>
        <td>' . $participante . '</td>
        <td>Tipo de participante</td>
        <td>' . $propuesta->getParticipantes()->getUsuariosperfiles()->getPerfiles()->nombre . '</td>
    </tr>    
    <tr>
        <td><b>Estado</b></td>
        <td><b>' . $propuesta->getEstados()->nombre . '</b></td>
        <td>Nombre de la propuesta</td>
        <td>' . $propuesta->nombre . '</td>
    </tr>    
    <tr>
        <td>¿Su propuesta se desarrolla en Bogotá D.C.?</td>
        <td>' . $bogota . '</td>
        <td>Localidad</td>
        <td>' . $propuesta->getLocalidades()->nombre . '</td>
    </tr>    
    <tr>
        <td>Upz</td>
        <td>' . $propuesta->getUpzs()->nombre . '</td>
        <td>Barrio</td>
        <td>' . $propuesta->getBarrios()->nombre . '</td>
    </tr>    
</table>
<h3>Información del participante</h3>
' . $tabla_participante . '
<h3>Documentación cargada en la subsanación</h3>
<table>    
    <tr>
        <td align="center" bgcolor="#BDBDBD">N°</td>
        <td align="center" bgcolor="#BDBDBD">Requisito</td>    
        <td align="center" bgcolor="#BDBDBD">Nombre del archivo</td>        
    </tr> 
    ' . $html_administrativos . '
</table>
<br/><br/>
<table>    
    <tr>
        <td align="center" bgcolor="#BDBDBD">N°</td>
        <td align="center" bgcolor="#BDBDBD">Requisito</td>    
        <td align="center" bgcolor="#BDBDBD">Link</td>        
    </tr>       
    ' . $html_administrativos_link . '
</table>
';
                    $logger->info('"token":"{token}","user":"{user}","message":"Se genero el reporte de inscripcion de la propuesta (' . $request->getPut('id') . ')', ['user' => "", 'token' => $request->getPut('token')]);
                    $logger->close();

                    echo $html;
                } else {
                    $logger->info('"token":"{token}","user":"{user}","message":"Se genero el reporte de inscripcion de la propuesta (' . $request->getPut('id') . ')', ['user' => "", 'token' => $request->getPut('token')]);
                    $logger->close();

                    echo "<b>No es posible generar el reporte, debido a que su propuesta no esta en estado inscrita.</br>";
                    exit;
                }
            } else {
                //Registro la accion en el log de convocatorias           
                $logger->error('"token":"{token}","user":"{user}","message":"La propuesta (' . $request->getPut('id') . ') no existe en el metodo reporte_propuesta_inscrita', ['user' => "", 'token' => $request->getPut('token')]);
                $logger->close();
                echo "error_propuesta";
            }
        } else {
            //Registro la accion en el log de convocatorias           
            $logger->error('"token":"{token}","user":"{user}","message":"Token caduco en el metodo reporte_propuesta_inscrita al generar el reporte de la propuesta (' . $request->getPut('id') . ')', ['user' => "", 'token' => $request->getPut('token')]);
            $logger->close();
            echo "error_token";
        }
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias           
        $logger->error('"token":"{token}","user":"{user}","message":"Error metodo reporte_propuesta_inscrita al generar el reporte de la propuesta (' . $request->getPut('id') . ')' . $ex->getMessage() . '"', ['user' => "", 'token' => $request->getPut('token')]);
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

function weekOfMonth($qDate) {
    $dt = strtotime($qDate);
    $day  = date('j',$dt);
    $month = date('m',$dt);
    $year = date('Y',$dt);
    $totalDays = date('t',$dt);
    $weekCnt = 1;
    $retWeek = 0;
    for($i=1;$i<=$totalDays;$i++) {
        $curDay = date("N", mktime(0,0,0,$month,$i,$year));
        if($curDay==7) {
            if($i==$day) {
                $retWeek = $weekCnt+1;
            }
            $weekCnt++;
        } else {
            if($i==$day) {
                $retWeek = $weekCnt;
            }
        }
    }
    return $retWeek;
}


?>