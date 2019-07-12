<?php
use Phalcon\Mvc\Model;

class Convocatoriasparticipantes extends Model
{
    public $id;
    
    public function initialize() {
        
        //Se define relacion de N a 1 con tipos participantes
        $this->belongsTo(
                'tipo_participante', 'Tiposparticipantes', 'id'
        );
                
    }
    
}