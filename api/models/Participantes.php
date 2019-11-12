<?php
use Phalcon\Mvc\Model;

class Participantes extends Model
{
    public $id;

    //Cesar Britto
    public function initialize()
   {

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

        //hasOne	Defines a 1-1 relationship
       $this->hasOne(
           'tipo_documento',
           'Tiposdocumentos',
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
