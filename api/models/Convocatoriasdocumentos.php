<?php
use Phalcon\Mvc\Model;

class Convocatoriasdocumentos extends Model
{
    public $id;
    
    public function initialize()
    {
        //Se define relacion de N a 1 con tipos_eventos
        $this->belongsTo(
                'requisito', 'Requisitos', 'id'
        );
    }
}