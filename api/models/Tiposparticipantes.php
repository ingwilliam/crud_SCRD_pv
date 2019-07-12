<?php
use Phalcon\Mvc\Model;

class Tiposparticipantes extends Model
{
    public $id;
    
    public function initialize()
    {
        //Se define la relación con 1 a N con Convocatorias participantes
        $this->hasMany(
            'id',
            'Convocatoriasparticipantes',
            'tipo_participante'
        );                        
    }
    
}