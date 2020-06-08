<?php
use Phalcon\Mvc\Model;

class Gruposevaluadores extends Model
{
    public $id;

    public function initialize()
    {
        //Se define la relación con 1 a N con Convocatorias
        $this->hasMany(
            'id',
            'Convocatoriasrondas',
            'grupoevaluador'
        );
        
        //Se define la relación con 1 a N con Convocatorias
        $this->hasMany(
            'id',
            'Evaluadores',
            'grupoevaluador'
        );
    }

}
