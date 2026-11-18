<?php declare(strict_types=1);

namespace Plugin\TradeMaster\Actions;

use App\Domain\AbstractAction;
use App\Domain\AbstractTask;
use App\Domain\Tasks\SendMailTask;
use Plugin\TradeMaster\TradeMasterPlugin;

class CartConfirm extends AbstractAction
{
    protected function action(): \Slim\Psr7\Response
    {
        /** @var \App\Domain\Models\User|false $user */
        $user = $this->request->getAttribute('user', false);
        $data = [
            'nomer' => (string) $this->getQueryParam('nomer', ''),
            'user' => $user,
            'products' => [],
        ];

        if ($data['nomer'] === '' || !$user) {
            return $this->respondWithJson(['0']);
        }

        /** @var TradeMasterPlugin $tm */
        $tm = $this->container->get('TradeMasterPlugin');

        $data['products'] = $tm->api([
            'endpoint' => 'order/getSchet',
            'params' => ['nomer' => $data['nomer']],
        ]);

        $tpl = $this->parameter('TradeMasterPlugin_mail_order_template', '');

        if (!$data['products'] || !$user->email || $tpl === '') {
            return $this->respondWithJson(['0']);
        }

        // письмо клиенту и админу
        $task = new SendMailTask($this->container);
        $task->execute([
            'to' => $user->email,
            'bcc' => $this->parameter('mail_from', ''),
            'template' => $this->render($tpl, $data),
            'isHtml' => true,
        ]);

        AbstractTask::worker($task);

        return $this->respondWithJson([1]);
    }
}
