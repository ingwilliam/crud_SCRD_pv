<?php
use Phalcon\Mvc\Model;

class Convocatoriascronogramas extends Model
{
    public $id;
    
    public function initialize()
    {
        //Se define relacion de N a 1 con tipos_eventos
        $this->belongsTo(
                'tipo_evento', 'Tiposeventos', 'id'
        );
    }
        
}