<?php
declare(strict_types=1);

use System\Engine\Controller;

class ToolsAdminMenu extends Controller
{
    public function indexAction(): void
    {
        $groupId = $this->request->get('group', 'string', 'main');
        
        $menuManager = new \System\Services\MenuManager(
            $this->db, $this->registry->get('user'), 
            $this->config, ''
        );
        
        $groups = $menuManager->getAllGroups();
        $items = $menuManager->getItemsForAdmin($groupId);
        
        $this->view->inline(function() {

        });
    }

    public function saveAction(): void
    {
        $id = $this->request->post('id', 'int');
        $data = $this->request->post('item', 'raw');
        
        $menuManager = new \System\Services\MenuManager($this->db, null, $this->config, '');
        $menuManager->saveItem($data, $id ?: null);
        
        $this->notify->success('Menu saved.');
        redirect_to('tools/admin/menu', ['group' => $data['menu_group'] ?? 'main']);
    }

    public function deleteAction(): void
    {
        $id = $this->request->post('id', 'int');
        (new \System\Services\MenuManager($this->db, null, $this->config, ''))
            ->deleteItem($id);
        
        $this->notify->success('Item deleted.');
        redirect_to('tools/admin/menu');
    }

    public function reorderAction(): void
    {
        $order = $this->request->post('order', 'array', []);
        (new \System\Services\MenuManager($this->db, null, $this->config, ''))
            ->reorder($order);
        
        exit('OK');
    }
}