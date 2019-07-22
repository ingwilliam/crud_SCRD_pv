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
   }
}
