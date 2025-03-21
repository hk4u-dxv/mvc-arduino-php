<?php

/**
 * error_handler.config.php
 * Configuración de manejadores globales de errores y excepciones
 * 
 * Este archivo configura manejadores personalizados para capturar
 * errores de PHP y mostrarlos con plantilla personalizada.
 */

/**
 * Manejador de errores de PHP no capturados
 * 
 * @param int $level Nivel de error
 * @param string $message Mensaje de error
 * @param string $file Archivo donde ocurrió el error
 * @param int $line Línea donde ocurrió el error
 * @return bool Indica si el error fue manejado
 */
function errorHandlerGlobal(int $level, string $message, string $file, int $line): bool
{
    // Registro especial para errores relacionados con Arduino y puertos seriales
    if (
        strpos($message, 'COM') !== false ||
        strpos($message, 'fopen') !== false ||
        strpos($message, 'puerto serial') !== false ||
        strpos($message, 'serial port') !== false
    ) {

        $logFile = __DIR__ . '/../logs/arduino_errors.log';
        $logDir = dirname($logFile);

        // Crear directorio de logs si no existe
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        // Obtener información adicional de contexto
        $context = [
            'OS' => PHP_OS,
            'PHP_VERSION' => PHP_VERSION,
            'USER' => getenv('USERNAME') ?: getenv('USER'),
            'SERVER_SOFTWARE' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
            'CURRENT_PORT' => $_ENV['ARDUINO_PORT'] ?? 'No configurado',
            'ARDUINO_BAUDRATE' => $_ENV['ARDUINO_BAUDRATE'] ?? 'No configurado'
        ];

        // Formatear mensaje de error
        $errorLog = date('Y-m-d H:i:s') . " [ARDUINO ERROR] " .
            "Level: $level, Message: $message, File: $file:$line\n" .
            "Context: " . json_encode($context, JSON_UNESCAPED_SLASHES) . "\n" .
            "Stack trace: " . (new \Exception())->getTraceAsString() . "\n" .
            "----------------------------------------\n";

        // Escribir en archivo de log dedicado
        file_put_contents($logFile, $errorLog, FILE_APPEND);
    }

    // Para errores críticos, convertirlos en excepciones
    if (in_array($level, [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE, E_RECOVERABLE_ERROR, E_USER_ERROR])) {
        throw new ErrorException($message, 0, $level, $file, $line);
    }

    // Para advertencias y notificaciones en producción, solo registrarlas
    if ($_ENV['APP_DEBUG'] !== 'true') {
        // Registrar en logs pero no mostrar al usuario
        error_log("Error PHP ($level): $message en $file:$line");
        return true; // Evitar que PHP maneje el error
    }

    // Para modo de depuración, permitir que PHP muestre el error
    return false;
}

/**
 * Manejador de excepciones no capturadas
 * 
 * @param Throwable $e La excepción no capturada
 */
function exceptionHandlerGlobal(\Throwable $e): void
{
    // Verificar si ErrorController ya está cargado
    if (!class_exists('\\App\\Controllers\\ErrorController')) {
        // Si no está cargado, intentar cargarlo a través del autoloader
        if (!file_exists(__DIR__ . "/../app/Controllers/ErrorController.php")) {
            // Si no podemos cargar el controlador, mostrar un error básico
            http_response_code(500);
            die("Error crítico: No se pudo cargar el controlador de errores.");
        }
    }

    $mensaje = $e->getMessage();
    $codigo = 500;
    $titulo = "Error interno";
    $detalles = "File: " . $e->getFile() . " on line " . $e->getLine() .
        "\nTrace: " . $e->getTraceAsString();

    try {
        // Usar nuestro sistema de errores personalizado
        \App\Controllers\ErrorController::errorGenerico($codigo, $titulo, $mensaje, $detalles);
    } catch (\Throwable $e) {
        // Si falla el controlador de errores, mostrar un error básico
        http_response_code(500);
        if ($_ENV['APP_DEBUG'] === 'true') {
            die("Error crítico: " . $e->getMessage() . "\nEn archivo: " . $e->getFile() . " línea: " . $e->getLine());
        } else {
            die("Error interno del servidor. Por favor, intente más tarde.");
        }
    }
}

// Registrar los manejadores
set_error_handler('errorHandlerGlobal');
set_exception_handler('exceptionHandlerGlobal');

// Configurar el reporte de errores según el entorno
if (isset($_ENV['APP_DEBUG']) && $_ENV['APP_DEBUG'] === 'true') {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);
    ini_set('display_errors', 0);
}
