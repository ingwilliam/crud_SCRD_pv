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
        "host" => $config->database->host,
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
        if ($token_actual > 0) {

            $propuesta = Propuestas::findFirst($request->getPut('id'));

            if (isset($propuesta->id)) {
                $array_administrativos = array();
                $array_tecnicos = array();
                foreach ($propuesta->Propuestasdocumentos as $propuestadocumento) {
                    if ($propuestadocumento->getConvocatoriasdocumentos()->getRequisitos()->tipo_requisito == "Administrativos") {
                        $array_administrativos[$propuestadocumento->id]["requisito"] = $propuestadocumento->getConvocatoriasdocumentos()->getRequisitos()->nombre;
                        $array_administrativos[$propuestadocumento->id]["nombre"] = $propuestadocumento->nombre;
                    }

                    if ($propuestadocumento->getConvocatoriasdocumentos()->getRequisitos()->tipo_requisito == "Tecnicos") {
                        $array_tecnicos[$propuestadocumento->id]["requisito"] = $propuestadocumento->getConvocatoriasdocumentos()->getRequisitos()->nombre;
                        $array_tecnicos[$propuestadocumento->id]["nombre"] = $propuestadocumento->nombre;
                    }
                }

                $array_administrativos_link = array();
                $array_tecnicos_link = array();
                foreach ($propuesta->Propuestaslinks as $propuestalink) {
                    if ($propuestalink->getConvocatoriasdocumentos()->getRequisitos()->tipo_requisito == "Administrativos") {
                        $array_administrativos_link[$propuestalink->id]["requisito"] = $propuestalink->getConvocatoriasdocumentos()->getRequisitos()->nombre;
                        $array_administrativos_link[$propuestalink->id]["link"] = $propuestalink->link;
                    }

                    if ($propuestalink->getConvocatoriasdocumentos()->getRequisitos()->tipo_requisito == "Tecnicos") {
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
                if($propuesta->getParticipantes()->getUsuariosperfiles()->getPerfiles()->id==6)
                {
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
        <td>' . $propuesta->getParticipantes()->redes_sociales . '</td>
        <td>Página web, vínculo o blog</td>
        <td>' . $propuesta->getParticipantes()->links . '</td>
    </tr>           
</table>';                    
                }
                //Participante juridico
                if($propuesta->getParticipantes()->getUsuariosperfiles()->getPerfiles()->id==7)
                {
$conditions = ['id' => $propuesta->participante, 'participante_padre' => $propuesta->participante, 'tipo' => 'Junta', 'active' => true];

//Se crea todo el array de las rondas de evaluacion
$consulta_integrantes = Participantes::find(([
            'conditions' => 'id<>:id: AND participante_padre=:participante_padre: AND tipo=:tipo: AND active=:active:',
            'bind' => $conditions,
            "order" => 'id'
]));                    

$i = 1;    
$html_integrantes = "";
foreach ($consulta_integrantes as $integrante) {                
        $html_integrantes = $html_integrantes . "<tr>";
        $html_integrantes = $html_integrantes . "<td>" . $i . "</td>";
        $html_integrantes = $html_integrantes . "<td>" . $integrante->getTiposdocumentos()->descripcion . "</td>";        
        $html_integrantes = $html_integrantes . "<td>" . $integrante->numero_documento . "</td>";        
        $html_integrantes = $html_integrantes . "<td>" . $integrante->primer_nombre . " " . $integrante->segundo_nombre . "</td>";        
        $html_integrantes = $html_integrantes . "<td>" . $integrante->primer_apellido . " " . $integrante->segundo_apellido . "</td>";        
        $html_integrantes = $html_integrantes . "<td>" . $integrante->rol . "</td>";        
        $html_integrantes = $html_integrantes . "</tr>";
        $i++;                
}                    
                    
                    
                   $cuenta_sede= ($propuesta->getParticipantes()->cuenta_sede) ? 'Sí':'No';
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
        <td>' . $propuesta->getParticipantes()->redes_sociales . '</td>        
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
        <td align="center" bgcolor="#BDBDBD">Tipo de documento</td>    
        <td align="center" bgcolor="#BDBDBD">Número de documento de identificación</td>    
        <td align="center" bgcolor="#BDBDBD">Nombres</td>        
        <td align="center" bgcolor="#BDBDBD">Apellidos</td>        
        <td align="center" bgcolor="#BDBDBD">Rol que desempeña o ejecuta en la propuesta</td>                
    </tr> 
    ' . $html_integrantes. '
</table>
';                    
                }
                //Participante agrupacion
                if($propuesta->getParticipantes()->getUsuariosperfiles()->getPerfiles()->id==8)
                {
                    
$conditions = ['id' => $propuesta->participante, 'participante_padre' => $propuesta->participante, 'tipo' => 'Integrante', 'active' => true];

//Se crea todo el array de las rondas de evaluacion
$consulta_integrantes = Participantes::find(([
            'conditions' => 'id<>:id: AND participante_padre=:participante_padre: AND tipo=:tipo: AND active=:active:',
            'bind' => $conditions,
            "order" => 'id'
]));                    

$i = 1;    
$html_integrantes = "";
foreach ($consulta_integrantes as $integrante) {                
        $html_integrantes = $html_integrantes . "<tr>";
        $html_integrantes = $html_integrantes . "<td>" . $i . "</td>";
        $html_integrantes = $html_integrantes . "<td>" . $integrante->getTiposdocumentos()->descripcion . "</td>";        
        $html_integrantes = $html_integrantes . "<td>" . $integrante->numero_documento . "</td>";        
        $html_integrantes = $html_integrantes . "<td>" . $integrante->primer_nombre . " " . $integrante->segundo_nombre . "</td>";        
        $html_integrantes = $html_integrantes . "<td>" . $integrante->primer_apellido . " " . $integrante->segundo_apellido . "</td>";        
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
        <td>' . $propuesta->getParticipantes()->redes_sociales . '</td>            
        <td>Página web, vínculo o blog</td>
        <td>' . $propuesta->getParticipantes()->links . '</td>
    </tr> 
</table>
<h3>Integrantes</h3>
<table>    
    <tr>
        <td align="center" bgcolor="#BDBDBD">N°</td>
        <td align="center" bgcolor="#BDBDBD">Tipo de documento</td>    
        <td align="center" bgcolor="#BDBDBD">Número de documento de identificación</td>    
        <td align="center" bgcolor="#BDBDBD">Nombres</td>        
        <td align="center" bgcolor="#BDBDBD">Apellidos</td>        
        <td align="center" bgcolor="#BDBDBD">Rol que desempeña o ejecuta en la propuesta</td>                
    </tr> 
    ' . $html_integrantes. '
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
<h2  style="text-align:center;">CERTIFICADO DE INSCRIPCIÓN</h2>
<h3>Información de la propuesta</h3>        
<p>Su inscripción ha sido realizada correctamente. Recuerde que con la inscripción, su propuesta pasa al período de revisión de los requisitos formales del concurso, pero deberá estar atento en caso de que le sea solicitada la subsanación de alguno de los documentos.</p>
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
        <td>Resumen de la propuesta</td>
        <td>' . $propuesta->resumen . '</td>
        <td>Objetivo de la propuesta</td>
        <td>' . $propuesta->objetivo . '</td>
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
'.$tabla_participante.'
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
?>