<?php
use Phalcon\Mvc\Model;

class Propuestas extends Model
{
    public $id;

    public function initialize()
    {
      //belongsTo	Defines a n-1 relationship
        $this->belongsTo(
            'participante',
            'Participantes',
            'id'
        );

        //hasOne	Defines a 1-1 relationship
        $this->hasOne(
            'convocatoria',
            'Convocatorias',
            'id'
        );

    }


}
