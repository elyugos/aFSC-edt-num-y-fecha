<?php
/*
 * This file is part of FacturaSctipts
 * Copyright (C) 2014  Carlos Garcia Gomez  neorazorx@gmail.com
 * Copyright (C) 2014  Francesc Pineda Segarra  shawe.ewahs@gmail.com
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 * 
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */
require_model('md_numero.php');
require_model('articulo.php');
require_model('cliente.php');
require_model('ejercicio.php');
require_model('pedido_cliente.php');
require_model('familia.php');
require_model('impuesto.php');
require_model('linea_presupuesto_cliente.php');
require_model('presupuesto_cliente.php');
require_model('regularizacion_iva.php');
require_model('serie.php');
require_model('numeros.php');
class ventas_presupuesto extends fs_controller {
    public $modelonumero;
    public $agente;
   public $cliente;
   public $cliente_s;
   public $ejercicio;
   public $familia;
   public $impuesto;
   public $nuevo_presupuesto_url;
   public $presupuesto;
   public $serie;

   public function __construct()
   {
      parent::__construct(__CLASS__, ucfirst(FS_PRESUPUESTO), 'ventas', FALSE, FALSE);
   }

   protected function process()
   {
    $this->modelonumero = new modelonumero();
      $this->ppage = $this->page->get('ventas_presupuestos');
      $this->agente = FALSE;

      /// desactivamos la barra de botones
      $this->show_fs_toolbar = FALSE;

      $presupuesto = new presupuesto_cliente();
      $this->presupuesto = FALSE;
      $this->cliente = new cliente();
      $this->cliente_s = FALSE;
      $this->ejercicio = new ejercicio();
      $this->familia = new familia();
      $this->impuesto = new impuesto();
      $this->nuevo_presupuesto_url = FALSE;
      $this->serie = new serie();

      /**
       * Comprobamos si el usuario tiene acceso a nueva_venta,
       * necesario para poder añadir líneas.
       */
      if ($this->user->have_access_to('nueva_venta', FALSE))
      {
         $nuevoprep = $this->page->get('nueva_venta');
         if ($nuevoprep)
            $this->nuevo_presupuesto_url = $nuevoprep->url();
      }

      if (isset($_POST['idpresupuesto']))
      {
         $this->presupuesto = $presupuesto->get($_POST['idpresupuesto']);
         $this->modificar();
      }
      else if (isset($_GET['id']))
      {
         $this->presupuesto = $presupuesto->get($_GET['id']);
      }

      if ($this->presupuesto)
      {
         $this->page->title = $this->presupuesto->codigo;

         /// cargamos el agente
         if (!is_null($this->presupuesto->codagente))
         {
            $agente = new agente();
            $this->agente = $agente->get($this->presupuesto->codagente);
         }

         /// cargamos el cliente
         $this->cliente_s = $this->cliente->get($this->presupuesto->codcliente);

         /// comprobamos el presupuesto
         if ($this->presupuesto->full_test())
         {
            if( strtotime($this->presupuesto->finoferta) < strtotime(Date('d-m-Y')) AND $this->presupuesto->status != 2)
            {
               $this->new_advice("Fecha validez del " . FS_PRESUPUESTO . " vencida.");
               $this->presupuesto->status = 2; /// rechazado
               $this->presupuesto->save();
            }
            else if (isset($_REQUEST['status']))
            {
               $this->presupuesto->status = intval($_REQUEST['status']);
               
               if($this->presupuesto->status == 1 AND is_null($this->presupuesto->idpedido))
               {
                  $this->generar_pedido();
               }
               else if ($this->presupuesto->save())
               {
                  $this->new_message(ucfirst(FS_PRESUPUESTO)." modificado correctamente.");
               }
               else
               {
                  $this->new_error_msg("¡Imposible modificar el ".FS_PRESUPUESTO."!");
               }
            }
            else
            {
               /// Comprobamos las líneas
               $this->check_lineas();
            }
         }
      }
      else
         $this->new_error_msg("¡" . ucfirst(FS_PRESUPUESTO) . " de cliente no encontrado!");
   }

   /**
    * Comprobamos si los artículos han variado su precio.
    * @return type
    */
   private function check_lineas()
   {
      if( is_null($this->presupuesto->idpedido) AND $this->presupuesto->status == 0 )
      {
         foreach ($this->presupuesto->get_lineas() as $l)
         {
            $data = $this->db->select("SELECT factualizado,pvp FROM articulos WHERE referencia = " . $l->var2str($l->referencia) . " ORDER BY referencia ASC;");
            if (strtotime($data[0]["factualizado"]) > strtotime($this->presupuesto->fecha))
            {
               if ($l->pvpunitario > floatval($data[0]['pvp']))
               {
                  $this->new_advice("El precio del artículo <a href='" . $l->articulo_url() . "'>" . $l->referencia . "</a>"
                          . " ha bajado desde la elaboración del " . FS_PRESUPUESTO . ".");
               }
               else if ($l->pvpunitario < floatval($data[0]['pvp']))
               {
                  $this->new_advice("El precio del artículo <a href='" . $l->articulo_url() . "'>" . $l->referencia . "</a>"
                          . " ha subido desde la elaboración del " . FS_PRESUPUESTO . ".");
               }
            }
         }
      }
   }

   public function url()
   {
      if (!isset($this->presupuesto))
      {
         return parent::url();
      }
      else if ($this->presupuesto)
      {
         return $this->presupuesto->url();
      }
      else
         return $this->page->url();
   }

   private function modificar()
   {
      $this->presupuesto->observaciones = $_POST['observaciones'];
      $this->presupuesto->numero2 = $_POST['numero2'];

      if (is_null($this->presupuesto->idpedido))
      {
         /// obtenemos los datos del ejercicio para acotar la fecha
         $eje0 = $this->ejercicio->get($this->presupuesto->codejercicio);
         if ($eje0)
         {
            $this->presupuesto->fecha = $eje0->get_best_fecha($_POST['fecha'], TRUE);
            $this->presupuesto->finoferta = $_POST['finoferta'];
            $this->presupuesto->hora = $_POST['hora'];
         }
         else
            $this->new_error_msg('No se encuentra el ejercicio asociado al ".FS_PRESUPUESTO."');

         /// ¿cambiamos el cliente?
         if ($_POST['cliente'] != $this->presupuesto->codcliente)
         {
            $cliente = $this->cliente->get($_POST['cliente']);
            if ($cliente)
            {
               foreach ($cliente->get_direcciones() as $d)
               {
                  if ($d->domfacturacion)
                  {
                     $this->presupuesto->codcliente = $cliente->codcliente;
                     $this->presupuesto->cifnif = $cliente->cifnif;
                     $this->presupuesto->nombrecliente = $cliente->nombrecomercial;
                     $this->presupuesto->apartado = $d->apartado;
                     $this->presupuesto->ciudad = $d->ciudad;
                     $this->presupuesto->coddir = $d->id;
                     $this->presupuesto->codpais = $d->codpais;
                     $this->presupuesto->codpostal = $d->codpostal;
                     $this->presupuesto->direccion = $d->direccion;
                     $this->presupuesto->provincia = $d->provincia;
                     break;
                  }
               }
            }
            else
               die('No se ha encontrado el cliente.');
         }
         else
            $cliente = $this->cliente->get($this->presupuesto->codcliente);

         $serie = $this->serie->get($this->presupuesto->codserie);

         /// ¿cambiamos la serie?
         if ($_POST['serie'] != $this->presupuesto->codserie)
         {
            $serie2 = $this->serie->get($_POST['serie']);
            if ($serie2)
            {
               $this->presupuesto->codserie = $serie2->codserie;
               $this->presupuesto->irpf = $serie2->irpf;
               $this->presupuesto->new_codigo();

               $serie = $serie2;
            }
         }

         if (isset($_POST['numlineas']))
         {
            $numlineas = intval($_POST['numlineas']);

            $this->presupuesto->neto = 0;
            $this->presupuesto->totaliva = 0;
            $this->presupuesto->totalirpf = 0;
            $this->presupuesto->totalrecargo = 0;
            $lineas = $this->presupuesto->get_lineas();
            $articulo = new articulo();

            /// eliminamos las líneas que no encontremos en el $_POST
            foreach($lineas as $l)
            {
               $encontrada = FALSE;
               for($num = 0; $num <= $numlineas; $num++)
               {
                  if( isset($_POST['idlinea_'.$num]) )
                  {
                     if($l->idlinea == intval($_POST['idlinea_'.$num]))
                     {
                        $encontrada = TRUE;
                        break;
                     }
                  }
               }
               if (!$encontrada)
               {
                  if (!$l->delete())
                     $this->new_error_msg("¡Imposible eliminar la línea del artículo " . $l->referencia . "!");
               }
            }

            /// modificamos y/o añadimos las demás líneas
            for ($num = 0; $num <= $numlineas; $num++)
            {
               $encontrada = FALSE;
               if (isset($_POST['idlinea_' . $num]))
               {
                  foreach ($lineas as $k => $value)
                  {
                     /// modificamos la línea
                     if ($value->idlinea == intval($_POST['idlinea_' . $num]))
                     {
                        $encontrada = TRUE;
                        $lineas[$k]->cantidad = floatval($_POST['cantidad_' . $num]);
                        $lineas[$k]->pvpunitario = floatval($_POST['pvp_' . $num]);
                        $lineas[$k]->dtopor = floatval($_POST['dto_' . $num]);
                        $lineas[$k]->dtolineal = 0;
                        $lineas[$k]->pvpsindto = ($value->cantidad * $value->pvpunitario);
                        $lineas[$k]->pvptotal = ($value->cantidad * $value->pvpunitario * (100 - $value->dtopor) / 100);
                        $lineas[$k]->descripcion = $_POST['desc_' . $num];

                        $lineas[$k]->codimpuesto = NULL;
                        $lineas[$k]->iva = 0;
                        $lineas[$k]->recargo = 0;
                        $lineas[$k]->irpf = $this->presupuesto->irpf;
                        if (!$serie->siniva AND $cliente->regimeniva != 'Exento')
                        {
                           $imp0 = $this->impuesto->get_by_iva($_POST['iva_' . $num]);
                           if ($imp0)
                              $lineas[$k]->codimpuesto = $imp0->codimpuesto;

                           $lineas[$k]->iva = floatval($_POST['iva_' . $num]);
                           $lineas[$k]->recargo = floatval($_POST['recargo_' . $num]);
                        }

                        if ($lineas[$k]->save())
                        {
                           $this->presupuesto->neto += $value->pvptotal;
                           $this->presupuesto->totaliva += $value->pvptotal * $value->iva / 100;
                           $this->presupuesto->totalirpf += $value->pvptotal * $value->irpf / 100;
                           $this->presupuesto->totalrecargo += $value->pvptotal * $value->recargo / 100;
                        }
                        else
                           $this->new_error_msg("¡Imposible modificar la línea del artículo " . $value->referencia . "!");
                        break;
                     }
                  }

                  /// añadimos la línea
                  if (!$encontrada AND intval($_POST['idlinea_' . $num]) == -1 AND isset($_POST['referencia_' . $num]))
                  {
                     $art0 = $articulo->get($_POST['referencia_' . $num]);
                     if ($art0)
                     {
                        $linea = new linea_presupuesto_cliente();
                        $linea->referencia = $art0->referencia;
                        $linea->descripcion = $_POST['desc_' . $num];
                        $linea->irpf = $this->presupuesto->irpf;

                        if (!$serie->siniva AND $cliente->regimeniva != 'Exento')
                        {
                           $imp0 = $this->impuesto->get_by_iva($_POST['iva_'.$num]);
                           if ($imp0)
                              $linea->codimpuesto = $imp0->codimpuesto;

                           $linea->iva = floatval($_POST['iva_' . $num]);
                           $linea->recargo = floatval($_POST['recargo_' . $num]);
                        }

                        $linea->idpresupuesto = $this->presupuesto->idpresupuesto;
                        $linea->cantidad = floatval($_POST['cantidad_' . $num]);
                        $linea->pvpunitario = floatval($_POST['pvp_' . $num]);
                        $linea->dtopor = floatval($_POST['dto_' . $num]);
                        $linea->pvpsindto = ($linea->cantidad * $linea->pvpunitario);
                        $linea->pvptotal = ($linea->cantidad * $linea->pvpunitario * (100 - $linea->dtopor) / 100);

                        if( $linea->save() )
                        {
                           $this->presupuesto->neto += $linea->pvptotal;
                           $this->presupuesto->totaliva += $linea->pvptotal * $linea->iva / 100;
                           $this->presupuesto->totalirpf += $linea->pvptotal * $linea->irpf / 100;
                           $this->presupuesto->totalrecargo += $linea->pvptotal * $linea->recargo / 100;
                        }
                        else
                           $this->new_error_msg("¡Imposible guardar la línea del artículo " . $linea->referencia . "!");
                     }
                     else
                        $this->new_error_msg("¡Artículo " . $_POST['referencia_' . $num] . " no encontrado!");
                  }
               }
            }

            /// redondeamos
            $this->presupuesto->neto = round($this->presupuesto->neto, FS_NF0);
            $this->presupuesto->totaliva = round($this->presupuesto->totaliva, FS_NF0);
            $this->presupuesto->totalirpf = round($this->presupuesto->totalirpf, FS_NF0);
            $this->presupuesto->totalrecargo = round($this->presupuesto->totalrecargo, FS_NF0);
            $this->presupuesto->total = $this->presupuesto->neto + $this->presupuesto->totaliva - $this->presupuesto->totalirpf + $this->presupuesto->totalrecargo;

            if (abs(floatval($_POST['atotal']) - $this->presupuesto->total) > .01)
            {
               $this->new_error_msg("El total difiere entre el controlador y la vista (" . $this->presupuesto->total .
                       " frente a " . $_POST['atotal'] . "). Debes informar del error.");
            }
         }
      }

      if ($this->presupuesto->save())
      {
         $this->new_message(ucfirst(FS_PRESUPUESTO) . " modificado correctamente.");
         $this->new_change(ucfirst(FS_PRESUPUESTO) . ' Cliente ' . $this->presupuesto->codigo, $this->presupuesto->url());
      }
      else
         $this->new_error_msg("¡Imposible modificar el " . FS_PRESUPUESTO . "!");
   }

   private function generar_pedido()
   {
      $pedido = new pedido_cliente();
      $pedido->apartado = $this->presupuesto->apartado;
      $pedido->automatica = TRUE;
      $pedido->cifnif = $this->presupuesto->cifnif;
      $pedido->ciudad = $this->presupuesto->ciudad;
      $pedido->codagente = $this->presupuesto->codagente;
      $pedido->codalmacen = $this->presupuesto->codalmacen;
      $pedido->codcliente = $this->presupuesto->codcliente;
      $pedido->coddir = $this->presupuesto->coddir;
      $pedido->coddivisa = $this->presupuesto->coddivisa;
      $pedido->tasaconv = $this->presupuesto->tasaconv;
      $pedido->codpago = $this->presupuesto->codpago;
      $pedido->codpais = $this->presupuesto->codpais;
      $pedido->codpostal = $this->presupuesto->codpostal;
      $pedido->codserie = $this->presupuesto->codserie;
      $pedido->direccion = $this->presupuesto->direccion;
      $pedido->editable = TRUE;
      $pedido->neto = $this->presupuesto->neto;
      $pedido->nombrecliente = $this->presupuesto->nombrecliente;
      $pedido->observaciones = $this->presupuesto->observaciones;
      $pedido->provincia = $this->presupuesto->provincia;
      $pedido->total = $this->presupuesto->total;
      $pedido->totaliva = $this->presupuesto->totaliva;
      $pedido->numero2 = $this->presupuesto->numero2;
      $pedido->irpf = $this->presupuesto->irpf;
      $pedido->porcomision = $this->presupuesto->porcomision;
      $pedido->recfinanciero = $this->presupuesto->recfinanciero;
      $pedido->totalirpf = $this->presupuesto->totalirpf;
      $pedido->totalrecargo = $this->presupuesto->totalrecargo;

      /**
       * Obtenemos el ejercicio para la fecha de hoy (puede que no sea
       * el mismo ejercicio que el del presupuesto, por ejemplo si hemos cambiado de año).
       */
      $eje0 = $this->ejercicio->get_by_fecha($pedido->fecha);
      $pedido->codejercicio = $eje0->codejercicio;

      $regularizacion = new regularizacion_iva();

      if (!$eje0->abierto())
      {
         $this->new_error_msg("El ejercicio está cerrado.");
      }
      else if ($regularizacion->get_fecha_inside($pedido->fecha))
      {
         $this->new_error_msg("El IVA de ese periodo ya ha sido regularizado. No se pueden añadir más " . FS_PEDIDOS . " en esa fecha.");
      }
      else if ($pedido->save())
      {
         $continuar = TRUE;
         foreach ($this->presupuesto->get_lineas() as $l)
         {
            $n = new linea_pedido_cliente();
            $n->idpresupuesto = $l->idpresupuesto;
            $n->idpedido = $pedido->idpedido;
            $n->cantidad = $l->cantidad;
            $n->codimpuesto = $l->codimpuesto;
            $n->descripcion = $l->descripcion;
            $n->dtolineal = $l->dtolineal;
            $n->dtopor = $l->dtopor;
            $n->irpf = $l->irpf;
            $n->iva = $l->iva;
            $n->pvpsindto = $l->pvpsindto;
            $n->pvptotal = $l->pvptotal;
            $n->pvpunitario = $l->pvpunitario;
            $n->recargo = $l->recargo;
            $n->referencia = $l->referencia;
            if (!$n->save())
            {
               $continuar = FALSE;
               $this->new_error_msg("¡Imposible guardar la línea el artículo " . $n->referencia . "! ");
               break;
            }
         }

         if ($continuar)
         {
            $this->presupuesto->idpedido = $pedido->idpedido;
            $this->presupuesto->editable = FALSE;
            
            if ($this->presupuesto->save())
            {
               $this->new_message("<a href='" . $pedido->url() . "'>" . ucfirst(FS_PEDIDO) . '</a> generado correctamente.');
            }
            else
            {
               $this->new_error_msg("¡Imposible vincular el " . FS_PRESUPUESTO . " con el nuevo " . FS_PEDIDO . "!");
               if ($pedido->delete())
               {
                  $this->new_error_msg("El " . FS_PEDIDO . " se ha borrado.");
               }
               else
                  $this->new_error_msg("¡Imposible borrar el " . FS_PEDIDO . "!");
            }
         }
         else
         {
            if ($pedido->delete())
            {
               $this->new_error_msg("El " . FS_PEDIDO . " se ha borrado.");
            }
            else
               $this->new_error_msg("¡Imposible borrar el " . FS_PEDIDO . "!");
         }
      }
      else
         $this->new_error_msg("¡Imposible guardar el " . FS_PEDIDO . "!");
   }

   
    public function numtoletras($xcifra)
    {
        $xarray = array(0 => "Cero",
                1 => "UN", "DOS", "TRES", "CUATRO", "CINCO", "SEIS", "SIETE", "OCHO", "NUEVE",
                "DIEZ", "ONCE", "DOCE", "TRECE", "CATORCE", "QUINCE", "DIECISEIS", "DIECISIETE", "DIECIOCHO", "DIECINUEVE",
                "VEINTI", 30 => "TREINTA", 40 => "CUARENTA", 50 => "CINCUENTA", 60 => "SESENTA", 70 => "SETENTA", 80 => "OCHENTA", 90 => "NOVENTA",
                100 => "CIENTO", 200 => "DOSCIENTOS", 300 => "TRESCIENTOS", 400 => "CUATROCIENTOS", 500 => "QUINIENTOS", 600 => "SEISCIENTOS", 700 => "SETECIENTOS", 800 => "OCHOCIENTOS", 900 => "NOVECIENTOS"
        );
    //
        $xcifra = trim($xcifra);
        $xlength = strlen($xcifra);
        $xpos_punto = strpos($xcifra, ".");
        $xaux_int = $xcifra;
        $xdecimales = "00";
        if (!($xpos_punto === false)) {
            if ($xpos_punto == 0) {
                $xcifra = "0" . $xcifra;
                $xpos_punto = strpos($xcifra, ".");
            }
            $xaux_int = substr($xcifra, 0, $xpos_punto); // obtengo el entero de la cifra a convertir
            $xdecimales = substr($xcifra . "00", $xpos_punto + 1, 2); // obtengo los valores decimales
        }

        $XAUX = str_pad($xaux_int, 18, " ", STR_PAD_LEFT); // ajusto la longitud de la cifra, para que sea divisible por centenas de miles (grupos de 6)
        $xcadena = "";
        for ($xz = 0; $xz < 3; $xz++) {
            $xaux = substr($XAUX, $xz * 6, 6);
            $xi = 0;
            $xlimite = 6; // inicializo el contador de centenas xi y establezco el límite a 6 dígitos en la parte entera
            $xexit = true; // bandera para controlar el ciclo del While
            while ($xexit) {
                if ($xi == $xlimite) { // si ya ha llegado al límite máximo de enteros
                    break; // termina el ciclo
                }

                $x3digitos = ($xlimite - $xi) * -1; // comienzo con los tres primeros digitos de la cifra, comenzando por la izquierda
                $xaux = substr($xaux, $x3digitos, abs($x3digitos)); // obtengo la centena (los tres dígitos)
                for ($xy = 1; $xy < 4; $xy++) { // ciclo para revisar centenas, decenas y unidades, en ese orden
                    switch ($xy) {
                    case 1: // checa las centenas
                        if (substr($xaux, 0, 3) < 100) { // si el grupo de tres dígitos es menor a una centena ( < 99) no hace nada y pasa a revisar las decenas

                        } else {
                            $key = (int) substr($xaux, 0, 3);
                            if (TRUE === array_key_exists($key, $xarray)){  // busco si la centena es número redondo (100, 200, 300, 400, etc..)
                                $xseek = $xarray[$key];
                                $xsub = $this->subfijo($xaux); // devuelve el subfijo correspondiente (Millón, Millones, Mil o nada)
                                if (substr($xaux, 0, 3) == 100)
                                    $xcadena = " " . $xcadena . " CIEN " . $xsub;
                                else
                                    $xcadena = " " . $xcadena . " " . $xseek . " " . $xsub;
                                $xy = 3; // la centena fue redonda, entonces termino el ciclo del for y ya no reviso decenas ni unidades
                            }
                            else { // entra aquí si la centena no es numero redondo (101, 253, 120, 980, etc.)
                                $key = (int) substr($xaux, 0, 1) * 100;
                                $xseek = $xarray[$key]; // toma el primer caracter de la centena y lo multiplica por cien y lo busca en el arreglo (para que busque 100,200,300, etc)
                                $xcadena = " " . $xcadena . " " . $xseek;
                            } // ENDIF ($xseek)
                        } // ENDIF (substr($xaux, 0, 3) < 100)
                        break;
                    case 2: // Chequear las decenas (con la misma lógica que las centenas)
                        if (substr($xaux, 1, 2) < 10) {

                        } else {
                            $key = (int) substr($xaux, 1, 2);
                            if (TRUE === array_key_exists($key, $xarray)) {
                                $xseek = $xarray[$key];
                                $xsub = $this->subfijo($xaux);
                                if (substr($xaux, 1, 2) == 20)
                                    $xcadena = " " . $xcadena . " VEINTE " . $xsub;
                                else
                                    $xcadena = " " . $xcadena . " " . $xseek . " " . $xsub;
                                $xy = 3;
                            } else {
                                $key = (int) substr($xaux, 1, 1) * 10;
                                $xseek = $xarray[$key];
                                if (20 == substr($xaux, 1, 1) * 10)
                                    $xcadena = " " . $xcadena . " " . $xseek;
                                else
                                    $xcadena = " " . $xcadena . " " . $xseek . " Y ";
                            } // ENDIF ($xseek)
                        } // ENDIF (substr($xaux, 1, 2) < 10)
                        break;
                    case 3: // Chequear las unidades
                        if (substr($xaux, 2, 1) < 1) { // si la unidad es cero, ya no hace nada

                        } else {
                            $key = (int) substr($xaux, 2, 1);
                            $xseek = $xarray[$key]; // obtengo directamente el valor de la unidad (del uno al nueve)
                            $xsub = $this->subfijo($xaux);
                            $xcadena = " " . $xcadena . " " . $xseek . " " . $xsub;
                        } // ENDIF (substr($xaux, 2, 1) < 1)
                        break;
                    } // END SWITCH
                } // END FOR
                $xi = $xi + 3;
            } // ENDDO

            if (substr(trim($xcadena), -5, 5) == "ILLON") // si la cadena obtenida termina en MILLON o BILLON, entonces le agrega al final la conjuncion DE
                $xcadena.= " DE";

            if (substr(trim($xcadena), -7, 7) == "ILLONES") // si la cadena obtenida en MILLONES o BILLONES, entoncea le agrega al final la conjuncion DE
                $xcadena.= " DE";

            // ----------- esta línea la puedes cambiar de acuerdo a tus necesidades o a tu país -------
            if (trim($xaux) != "") {
                switch ($xz) {
                    case 0:
                        if (trim(substr($XAUX, $xz * 6, 6)) == "1")
                            $xcadena.= "UN BILLON ";
                        else
                            $xcadena.= " BILLONES ";
                        break;
                    case 1:
                        if (trim(substr($XAUX, $xz * 6, 6)) == "1")
                            $xcadena.= "UN MILLON ";
                        else
                            $xcadena.= " MILLONES ";
                        break;
                    case 2:
                        if ($xcifra < 1) {
                            $xcadena = "CERO con $xdecimales/100";
                        }
                        if ($xcifra >= 1 && $xcifra < 2) {
                            $xcadena = "UNO con $xdecimales/100";
                        }
                        if ($xcifra >= 2) {
                            $xcadena.= " con $xdecimales/100";
                        }
                        break;
                } // endswitch ($xz)
            } // ENDIF (trim($xaux) != "")

            $xcadena = str_replace("VEINTI ", "VEINTI", $xcadena); // quito el espacio para el VEINTI, para que quede: VEINTICUATRO, VEINTIUN, VEINTIDOS, etc
            $xcadena = str_replace("  ", " ", $xcadena); // quito espacios dobles
            $xcadena = str_replace("UN UN", "UN", $xcadena); // quito la duplicidad
            $xcadena = str_replace("  ", " ", $xcadena); // quito espacios dobles
            $xcadena = str_replace("BILLON DE MILLONES", "BILLON DE", $xcadena); // corrigo la leyenda
            $xcadena = str_replace("BILLONES DE MILLONES", "BILLONES DE", $xcadena); // corrigo la leyenda
            $xcadena = str_replace("DE UN", "UN", $xcadena); // corrigo la leyenda
        } // ENDFOR ($xz)

        $xcadena = str_replace("UN MIL ", "MIL ", $xcadena); // quito el BUG de UN MIL
        return trim($xcadena);
    }

    // END FUNCTION

    public function subfijo($xx)
    { // esta función genera un subfijo para la cifra
        $xx = trim($xx);
        $xstrlen = strlen($xx);
        if ($xstrlen == 1 || $xstrlen == 2 || $xstrlen == 3)
            $xsub = "";
        //
        if ($xstrlen == 4 || $xstrlen == 5 || $xstrlen == 6)
            $xsub = "MIL";
        //
        return $xsub;
    }

    
}
