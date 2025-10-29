#!/usr/bin/env php
<?php

////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
// Определение функций
// Обрезает файл до указанного числа строк
function trim_log(string $path, int $maxLines): void
{
    if ($maxLines <= 0 || !is_file($path)) {
        return;
    }

    // Читаем файл потоково и храним кольцевой буфер из последних $maxLines строк
    $fh = new SplFileObject($path, 'r');
    $buffer = [];
    foreach ($fh as $line) {
        if ($line === false) {
            break;
        }
        $buffer[] = rtrim($line, "\r\n");
        if (count($buffer) > $maxLines) {
            array_shift($buffer); // удаляем самую старую строку
        }
    }

    // Если строк и так <= maxLines — ничего не делаем
    // Но можно просто перезаписать тем же содержимым безопасно и атомарно
    $tmp = $path . '.tmp.' . getmypid();
    file_put_contents($tmp, implode(PHP_EOL, $buffer), LOCK_EX);
    // Атомарная замена файла
    rename($tmp, $path);
}

// функция получает на значение и карту преобразовния и возвращает инетерполированное значение
// если значение больше максимального или меньше минимального, возвращает ближайшее

function interp (float $Value, array $mapVal2Val): float
{
    if (array_key_exists($Value, $mapVal2Val)) {
        return $mapVal2Val[$Value];
    } else {
        // линейная интерполяция
        $lstBr = array_keys($mapVal2Val);
        sort($lstBr);
        for ($I = 1; $I < count($lstBr); $I++) {
            if ($Value < $lstBr[$I]) {
                $Br1 = $lstBr[$I - 1];
                $Br2 = $lstBr[$I];
                $Lux1 = $mapVal2Val[$Br1];
                $Lux2 = $mapVal2Val[$Br2];
                // линейная интерполяция
                $Result = $Lux1 + ($Value - $Br1) * ($Lux2 - $Lux1) / ($Br2 - $Br1);
                // если $Result меньше минимального
                if ($Result < min($mapVal2Val)) {
                    return min($mapVal2Val);;
                }
                return $Result;
            }
        }
    }
    // если значение больше максимального
    return end($mapVal2Val);
}

// функция которя получает на вход яркость
// таблицу яркость=>светимость ведущиего монитора и таблицу яркость=>светимость ведомого монитора
// и возвращает яркость ведомого монитора соответствующую яркости ведущего
function getBrightness (int $Brightness, array $mapMasterBr2Lux, array $mapSlaveBr2Lux): int
{
    // получим светимость мастера
    $LuxMaster = interp($Brightness, $mapMasterBr2Lux);

    // получим яркость слейва по этой светимости
    $BrSlave = interp($LuxMaster, array_flip($mapSlaveBr2Lux));

    return (int)round($BrSlave);
}


////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
// Основной код скрипта

// Получаем аргумент яркости для главного монитора
$Brightness = $argv[1] ?? -1;

// получим освещенность помещения с помощь скрипта lux
$cmdLux = __DIR__.'/lux';
exec($cmdLux, $lstOutputLux, $ReturnVarLux);
$Lux = $lstOutputLux[0];

if ($Brightness < 0) {
    // автоматическая установка яркости по освещенности помещения
    // загрузим данные из лога
    $lstLog = file(__DIR__.'/br.log', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $mapLux2Br = [];
    $mapBr2Lux = [];
    foreach ($lstLog as $LogLine) {
        list($DateLog, $BrLog, $LuxLog) = explode(',', $LogLine);
        $LuxLog = round(10000 * (float)trim($LuxLog));

        if (isset($mapBr2Lux[trim($BrLog)])) {
            $mapBr2Lux[trim($BrLog)] = intval(round(($mapBr2Lux[trim($BrLog)] + $LuxLog) / 2));
        } else {
            $mapBr2Lux[trim($BrLog)] = intval($LuxLog);
        }
    }
    
    //$mapBr2Lux[0] = 0; // яркость 0 соответствует освещенности 0
    
    asort($mapBr2Lux);
    $mapLux2Br = array_flip($mapBr2Lux);
    $mapLux2Br[0] = 0; // освещенность 0 соответствует яркости 0
    ksort($mapLux2Br);
    print_r($mapLux2Br); 

    // выровняем карту Lux2Br по возрастанию
    $proc_linearing = function (array $map): array
    {
        $map_linear = [];
        $PrevKey = 0;
        $PrevValue = 0;
        foreach ($map as $Key => $Value) {
            if ($Value < $PrevValue) {
                unset($map_linear[$PrevKey]);
                $map_linear[round(($PrevKey+$Key)/2)] = round(($PrevValue+$Value)/2);
            } else {
                $map_linear[$Key] = $Value;
            }
            $PrevValue = $Value;
            $PrevKey = $Key;
        }
        return $map_linear;
    };
    $mapLux2Br_linear = $proc_linearing($mapLux2Br);
    while (count($mapLux2Br_linear) < count($mapLux2Br)) {
        $mapLux2Br = $mapLux2Br_linear;
        $mapLux2Br_linear = $proc_linearing($mapLux2Br_linear);
    }

    print_r($mapLux2Br_linear); 
    // получим яркость по освещенности
    $LuxKey = round(10000 * (float)$Lux);
    $Brightness = (int)round(interp($LuxKey, $mapLux2Br_linear) );
    echo "Автояркость по освещенности $Lux => $Brightness\n";
    //die();
} else {
    # запишим данные в лог, чтобы собирать статистику
    $LogLine = '['.date('Y-m-d H:i:s')."],$Brightness,$Lux\n";
    file_put_contents(__DIR__.'/br.log', $LogLine, FILE_APPEND);

    // обрежем лог до 1000 строк
    trim_log(__DIR__.'/br.log', 1000);
}


// получаем конфигурацию мониторов
$lstMonitors = include __DIR__ . '/monitors.php';
$dctMaster = $lstMonitors[0]; // главный монитор


// уставновим яркости для всех мониторов использую скрипт brightness
foreach ($lstMonitors as $I => $dctMonitor) {
    $Br = ($I == 0) ? $Brightness : getBrightness($Brightness, $dctMaster['brightness2lux'], $dctMonitor['brightness2lux']);
    $cmd = __DIR__."/brightness ".escapeshellarg($dctMonitor['dev'])." ".escapeshellarg($Br);
    echo "$cmd\n";
    exec($cmd, $output, $return_var);
    if ($return_var != 0) {
        echo "Ошибка выполнения команды: $cmd\n";
    }
}

