<?php
use Phalcon\Mvc\Model;

class Juradospostulados extends Model
{
    public $id;

    public function initialize()
    {

      //hasOne	Defines a 1-1 relationship
      $this->hasOne(
        'convocatoria',
        'Convocatorias',
        'id',
      //  ['alias' => 'Convocatorias']
      );

      //hasOne	Defines a 1-1 relationship
      $this->hasOne(
        'propuesta',
        'Propuestas',
        'id',
        ['alias' => 'Propuestas']
      );

      $this->hasMany(
        'id',
        'Juradosnotificaciones',
        'juradospostulado',
        ['alias' => 'Notificaciones']
      );

    }

     public function validation(){
       /*

       if ($this->area_conocimiento == "") {
         $this->area_conocimiento = null;
       }


       if ($this->nucleo_basico == "") {
         $this->nucleo_basico = null;
       }
       */
       return true;
     }


}
