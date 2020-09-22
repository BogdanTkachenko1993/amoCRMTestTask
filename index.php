<?php
define('TOKEN_FILE', $_SERVER['DOCUMENT_ROOT'] . DIRECTORY_SEPARATOR . 'amocrm_token.json');
use Symfony\Component\Dotenv\Dotenv;
use League\OAuth2\Client\Token\AccessToken;
use AmoCRM\Client\AmoCRMApiClient;
use AmoCRM\Filters\LeadsFilter;
use AmoCRM\Helpers\EntityTypesInterface;
use AmoCRM\Models\AccountModel;
use AmoCRM\Models\TaskModel;
use AmoCRM\Collections\TasksCollection;
use AmoCRM\Exceptions\AmoCRMApiException;
use AmoCRM\Exceptions\AmoCRMApiNoContentException;


require_once('vendor/autoload.php');
require_once('token_functions.php');

session_start();

$dotenv = new Dotenv();
$dotenv->load(__DIR__ . '/.env');

$clientId = $_ENV['CLIENT_ID'];
$clientSecret = $_ENV['CLIENT_SECRET'];
$redirectUri = $_ENV['CLIENT_REDIRECT_URI'];

$apiClient = new AmoCRMApiClient($clientId, $clientSecret, $redirectUri);

$accessToken = getToken();

$apiClient->setAccessToken($accessToken)
        ->setAccountBaseDomain($accessToken->getValues()['baseDomain'])
        ->onAccessTokenRefresh(
            function (\League\OAuth2\Client\Token\AccessTokenInterface $accessToken, string $baseDomain) 
            {
                saveToken(
                    [
                        'accessToken' => $accessToken->getToken(),
                        'refreshToken' => $accessToken->getRefreshToken(),
                        'expires' => $accessToken->getExpires(),
                        'baseDomain' => $baseDomain,
                    ]
                );
            }
        );

$leads = $apiClient->leads();

$filter = new LeadsFilter();
$filter->setClosestTaskAt(0);
try
{
    $filteredLeads = $leads->get($filter);
}
catch (AmoCRMApiNoContentException $e)
{
    echo('Сделки без открытых задач отсутствуют');
    die;
}

$leadIds = [];

while(true)
{
    try
    {
        foreach($filteredLeads as $lead)
        {
            $leadIds[] = $lead->id;
        }
        $filteredLeads = $leads->nextPage($filteredLeads);
    }
    catch (AmoCRMApiNoContentException $e)
    {
        break;
    }
}

$account = $apiClient->account()->getCurrent(AccountModel::getAvailableWith());
$currentUserId = $account->currentUserId;

$tasksCollection = new TasksCollection();

if ($leadIds != null)
{
    foreach ($leadIds as $leadId)
    {
        $task = new TaskModel();
        $task->setTaskTypeId(TaskModel::TASK_TYPE_ID_CALL)
            ->setText('Сделка без задачи')
            ->setCompleteTill(mktime(10, 0, 0, 10, 3, 2020))
            ->setEntityType(EntityTypesInterface::LEADS)
            ->setEntityId($leadId)
            ->setDuration(30 * 60 * 60)
            ->setResponsibleUserId($currentUserId);
        $tasksCollection->add($task);
    }
}

try {
    $tasksCollection = $apiClient->tasks()->add($tasksCollection);
} catch (AmoCRMApiException $e) {
    echo $e->getMessage();
    die;
}

echo "Для всех сделок без открытых задач добавлена новая задача с текстом \"Сделка без задачи\"";