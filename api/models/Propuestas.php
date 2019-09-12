<?php
use Phalcon\Mvc\Model;

class Propuestas extends Model
{
    public $id;

    public function initialize()
    {
        //hasOne	Defines a 1-1 relationship
        $this->hasOne(
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

        //hasMany	Defines a 1-n relationship
        $this->hasOne(
            'id',
            'Educacionformal',
            'propuesta'
        );

    }


}
