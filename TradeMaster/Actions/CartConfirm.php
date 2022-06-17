<?php declare(strict_types=1);

namespace Plugin\TradeMaster\Actions;

use App\Domain\AbstractAction;
use Plugin\TradeMaster\TradeMasterPlugin;

class CartConfirm extends AbstractAction
{
    protected function action(): \Slim\Psr7\Response
    {
        $default = [
            'nomer' => '',
            'user' => false,
            'products' => [],
        ];
        $data = array_merge($default, $this->request->getQueryParams());
        $output = '0';

        if (($user = $this->request->getAttribute('user', false)) !== false) {
            $data['user'] = $user;
        }

        if ($data['nomer'] && $data['user']) {
            /** @var TradeMasterPlugin $tm */
            $tm = $this->container->get('TradeMasterPlugin');

            $data['products'] = $tm->api([
                'endpoint' => 'order/getSchet',
                'params' => ['nomer' => $data['nomer']],
            ]);

            if ($data['products']) {
                $renderer = $this->container->get('view');

                if (($path = realpath(THEME_DIR . '/' . $this->parameter('common_theme', 'default'))) !== false) {
                    $renderer->getLoader()->addPath($path);
                }

                // письмо клиенту и админу
                if (
                    $data['user']->getEmail() &&
                    ($tpl = $this->parameter('TradeMasterPlugin_mail_order_template', '')) !== ''
                ) {
                    $task = new \App\Domain\Tasks\SendMailTask($this->container);
                    $task->execute([
                        'to' => $data['user']->getEmail(),
                        'bcc' => $this->parameter('smtp_from', ''),
                        'body' => $renderer->fetch($tpl, $data),
                        'isHtml' => true,
                    ]);
                    \App\Domain\AbstractTask::worker($task);

                    $output = 1;
                }
            }
        }

        return $this->respondWithJson([$output]);
    }
}
