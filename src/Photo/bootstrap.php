<?php

/**
 * Moduł kontroli jakości zdjęcia do legitymacji.
 *
 * Projekt nie ma autoloadera, więc jeden require wciąga cały moduł:
 *
 *     require __DIR__ . '/../src/Photo/bootstrap.php';
 */

require_once __DIR__ . '/PhotoException.php';
require_once __DIR__ . '/PhotoReport.php';
require_once __DIR__ . '/PhotoConfig.php';
require_once __DIR__ . '/ImageProbe.php';
require_once __DIR__ . '/HttpClient.php';
require_once __DIR__ . '/FaceApiResult.php';
require_once __DIR__ . '/FaceApiInterface.php';
require_once __DIR__ . '/HaarCascade.php';
require_once __DIR__ . '/LocalFaceDetector.php';
require_once __DIR__ . '/LocalFaceApi.php';
require_once __DIR__ . '/FacePlusPlusApi.php';
require_once __DIR__ . '/CompreFaceApi.php';
require_once __DIR__ . '/FaceApiFactory.php';
require_once __DIR__ . '/PhotoAnalyzer.php';
require_once __DIR__ . '/DocumentScan.php';
