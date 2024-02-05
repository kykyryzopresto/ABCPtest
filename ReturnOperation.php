<?php

namespace NW\WebService\References\Operations\Notification;

class TsReturnOperation extends ReferencesOperation
{
    const TYPE_NEW    = 1;
    const TYPE_CHANGE = 2;

    /**
     * @throws \Exception
     */
    public function doOperation(): array
    {
        $data = (array)$this->getRequest('data');
        $resellerId = (int)$data['resellerId'];
        $notificationType = (int)$data['notificationType'];
        $clientId = (int)$data['clientId'];
        $creatorId = (int)$data['creatorId'];
        $expertId = (int)$data['expertId'];
        $differences = (int)$data['differences'];
        $differences_to = (int)$data['differences']['to'];
        $differences_from = (int)$data['differences']['from'];

        $result = [
            'notificationEmployeeByEmail' => false,
            'notificationClientByEmail'   => false,
            'notificationClientBySms'     => [
                'isSent'  => false,
                'message' => '',
            ],
        ];

        if (!$resellerId) {
            $result['notificationClientBySms']['message'] = 'Empty resellerId';
            return $result;
        }

        if (!$notificationType) {
            $result['notificationClientBySms']['message'] = 'Empty notificationType';
            return $result;
        }

        if (Seller::getById($resellerId) === null) {
            $result['notificationClientBySms']['message'] = 'Seller not found!';
            return $result;
        }

        $client = Contractor::getById($clientId);
        if ($client === null || $client->type !== Contractor::TYPE_CUSTOMER) {
            $result['notificationClientBySms']['message'] = 'Client not found!';
            return $result;
        }


        $cr = Employee::getById($creatorId);
        if ($cr === null) {
            $result['notificationClientBySms']['message'] = 'Creator not found!';
            return $result;
        }

        $et = Employee::getById($expertId);
        if ($et === null) {
            $result['notificationClientBySms']['message'] = 'Expert not found!';
            return $result;
        }

        $differences_str = '';
        if ($notificationType === self::TYPE_NEW) {
            $differences_str = __('NewPositionAdded', null, $resellerId);
        } elseif ($notificationType === self::TYPE_CHANGE && !$differences) {
            $differences_str = __('PositionStatusHasChanged', [
                    'FROM' => Status::getName($differences_from),
                    'TO'   => Status::getName($differences_to),
                ], $resellerId);
        }

        $templateData = [
            'COMPLAINT_ID'       => (int)$data['complaintId'],
            'COMPLAINT_NUMBER'   => (string)$data['complaintNumber'],
            'CREATOR_ID'         => $creatorId,
            'CREATOR_NAME'       => $cr->getFullName(),
            'EXPERT_ID'          => $expertId,
            'EXPERT_NAME'        => $et->getFullName(),
            'CLIENT_ID'          => $clientId,
            'CLIENT_NAME'        => $client->getFullName(),
            'CONSUMPTION_ID'     => (int)$data['consumptionId'],
            'CONSUMPTION_NUMBER' => (string)$data['consumptionNumber'],
            'AGREEMENT_NUMBER'   => (string)$data['agreementNumber'],
            'DATE'               => (string)$data['date'],
            'DIFFERENCES'        => $differences_str,
        ];

        // Если хоть одна переменная для шаблона не задана, то не отправляем уведомления
        foreach ($templateData as $key => $tempData) {
            if (empty($tempData)) {
                $result['notificationClientBySms']['message'] = "Template Data ({$key}) is empty!";
                return $result;
            }
        }

        $emailFrom = getResellerEmailFrom($resellerId);
        // Получаем email сотрудников из настроек
        $emails = getEmailsByPermit($resellerId, 'tsGoodsReturn');
        if (!empty($emailFrom) && count($emails) > 0) {
            foreach ($emails as $email) {
                MessagesClient::sendMessage([
                    0 => [ // MessageTypes::EMAIL,
                           'emailFrom' => $emailFrom,
                           'emailTo'   => $email,
                           'subject'   => __('complaintEmployeeEmailSubject', $templateData, $resellerId),
                           'message'   => __('complaintEmployeeEmailBody', $templateData, $resellerId),
                    ],
                ], $resellerId, $client->id, NotificationEvents::CHANGE_RETURN_STATUS);
                $result['notificationEmployeeByEmail'] = true;

            }
        }

        // Шлём клиентское уведомление, только если произошла смена статуса
        if ($notificationType === self::TYPE_CHANGE && !$differences_to) {
            if (!empty($emailFrom) && !empty($client->email)) {
                MessagesClient::sendMessage([
                    0 => [  //MessageTypes::EMAIL,
                           'emailFrom' => $emailFrom,
                           'emailTo'   => $client->email,
                           'subject'   => __('complaintClientEmailSubject', $templateData, $resellerId),
                           'message'   => __('complaintClientEmailBody', $templateData, $resellerId),
                    ],
                ], $resellerId, $client->id, NotificationEvents::CHANGE_RETURN_STATUS, $differences_to);
                $result['notificationClientByEmail'] = true;
            }

            if (!empty($client->mobile)) {
                $error = "Error to send by mobile";
                $res = NotificationManager::send($resellerId, $client->id, NotificationEvents::CHANGE_RETURN_STATUS, $differences_to, $templateData, $error);
                if ($res) {
                    $result['notificationClientBySms']['isSent'] = true;
                } else {
                    $result['notificationClientBySms']['message'] = $error;
                }
            }
        }

        return $result;
    }
}
