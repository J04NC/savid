<?php

class CrudController
{

    public function index()
    {

        $ruta = $_GET['url'] ?? '';

        $ruta = trim($ruta,'/');

        $crudService = new CrudService();

        $item = $crudService->getItem($ruta);

        if(!$item){
            die("Item no encontrado");
        }

        $tabla = $ruta;

        $data = $crudService->getTableData($tabla);

        $columns = $crudService->getColumns($tabla);

        $acciones = $crudService->getAcciones($item['id']);

        require BASE_PATH . '/app/views/layouts/main.php';

    }

    public function specialFields($tabla, $column)
    {
        if ($tabla === 'usuario') {
            if ($column === 'empresa_id') {
                return [
                    'type' => 'select',
                    'options' => $this->getEmpresasUsuario(),
                    'label' => 'Empresas'
                ];
            }
            if ($column === 'sede_id') {
                return [
                    'type' => 'select',
                    'options' => $this->getSedesUsuario($_SESSION['empresa_id']),
                    'label' => 'Sedes'
                ];
            }
            if ($column === 'roles') { // ← COLUMNA VIRTUAL
                return [
                    'type' => 'multiselect',
                    'options' => $this->getRoles(),
                    'label' => 'Roles (plantillas)'
                ];
            }
            if ($column === 'permisos') {
                return [
                    'type' => 'button',
                    'label' => 'Gestionar Permisos',
                    'action' => '?url=usuario/permisos/' . $data['id']
                ];
            }
        }
        return null;
    }

}
