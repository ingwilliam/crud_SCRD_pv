<?php
use Phalcon\Mvc\Model;

class Participantes extends Model
{
    public $id;

    //Cesar Britto
    public function initialize()
   {

       $this->useDynamicUpdate(true);

      //hasOne	Defines a 1-1 relationship
       $this->hasOne(
           'usuario_perfil',
           'Usuariosperfiles',
           'id'
       );

       //hasOne	Defines a 1-1 relationship
       $this->hasOne(
           'id',
           'Propuestas',
           'participante'
       );

       //Se define relacion de N a 1 con Barrios
        $this->belongsTo(
            'barrio_residencia',
            'Barrios',
            'id',
            [
                'alias' => "Barriosresidencia"
            ]
        );

        //Se define relacion de N a 1 con Ciudades
        $this->belongsTo(
            'ciudad_nacimiento',
            'Ciudades',
            'id',
            [
                'alias' => "Ciudadesnacimiento"
            ]
        );

        //Se define relacion de N a 1 con Ciudades
        $this->belongsTo(
            'ciudad_residencia',
            'Ciudades',
            'id',
            [
                'alias' => "Ciudadesresidencia"
            ]
        );

        //Se define relacion de N a 1 con Tiposdocumentos
        $this->belongsTo(
            'tipo_documento',
            'Tiposdocumentos',
            'id'
        );

        //Se define relacion de N a 1 con Sexos
        $this->belongsTo(
            'sexo',
            'Sexos',
            'id'
        );

        //Se define relacion de N a 1 con Orientacionessexuales
        $this->belongsTo(
            'orientacion_sexual',
            'Orientacionessexuales',
            'id'
        );

        //Se define relacion de N a 1 con Identidadesgeneros
        $this->belongsTo(
            'identidad_genero',
            'Identidadesgeneros',
            'id'
        );

        //Se define relacion de N a 1 con Gruposetnicos
        $this->belongsTo(
            'grupo_etnico',
            'Gruposetnicos',
            'id'
        );

   }

   public function validation(){

    if ($this->orientacion_sexual == "") {
      $this->orientacion_sexual = null;
    }

     if ($this->identidad_genero == "") {
       $this->identidad_genero = null;
     }

      if ($this->grupo_etnico == "") {
        $this->grupo_etnico = null;
      }

      if ($this->ciudad_nacimiento == "") {
        $this->ciudad_nacimiento = null;
      }

     if ($this->barrio_residencia == "") {
       $this->barrio_residencia = null;
     }

     return true;
   }
}
