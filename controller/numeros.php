<?php

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

/**
 * Description of numeros
 *
 * @author evargas
 */
require_model('md_numero.php');

class numeros extends fs_controller {
    
    public $modelonumero;
    public $fecha;
    
    public function __construct()     {
    parent::__construct(__class__,'Números','contabilidad');
    }
    
    protected function process()
    {
    	/* aqui me pierdo     $this->modelonumero->public function_exists($numtoletras);    */

    	/// las clases hay que instanciarlas, sino no puedes trabajar con ellas
    	$this->modelonumero = new modelonumero();
        $this->fecha = "8-1-2015";
       	/// ahora el modelo md_numero está en la variable de clase $modelonumero
    	/// a las variables y funciones de clase se accede DESDE LA CLASE con $this->
    	/// pero desde la vista sólo se puede acceder a aquiellas que sean public,
    	/// por eso $modelonumero es public

    	/// ahora mira el archivo numeros.html
    }
     
}