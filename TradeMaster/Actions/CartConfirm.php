<?php declare(strict_types=1);

namespace Plugin\TradeMaster\Actions;

use App\Domain\AbstractAction;
use App\Domain\AbstractTask;
use Plugin\TradeMaster\TradeMasterPlugin;

class CartConfirm extends AbstractAction
{
    protected function action(): \Slim\Psr7\Response
    {
        $data = array_merge(
            ['nomer' => '', 'user' => false, 'products' => []],
            $this->request->getQueryParams(),
        );

        if (($user = $this->request->getAttribute('user', false)) !== false) {
            $data['user'] = $user;
        }

        if (!$data['nomer'] || !$data['user']) {
            return $this->respondWithJson(['0']);
        }

        /** @var TradeMasterPlugin $tm */
        $tm = $this->container->get('TradeMasterPlugin');

        $data['products'] = $tm->api([
            'endpoint' => 'order/getSchet',
            'params' => ['nomer' => $data['nomer']],
        ]);

        $tpl = $this->parameter('TradeMasterPlugin_mail_order_template', '');

        if (!$data['products'] || !$data['user']->getEmail() || $tpl === '') {
            return $this->respondWithJson(['0']);
        }

        $renderer = $this->container->get('view');

        if (($path = realpath(THEME_DIR . '/' . $this->parameter('common_theme', 'default'))) !== false) {
            $renderer->getLoader()->addPath($path);
        }

        // письмо клиенту и админу
        $task = new \App\Domain\Tasks\SendMailTask($this->container);
        $task->execute([
            'to' => $data['user']->getEmail(),
            'bcc' => $this->parameter('mail_from', ''),
            'template' => $this->render($tpl, $data),
            'isHtml' => true,
        ]);

        AbstractTask::worker($task);

        return $this->respondWithJson([1]);
    }
}
