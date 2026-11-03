<?php
session_start();
require_once __DIR__ . '/../src/bootstrap.php';

(new SubmitReviewAction(new ReviewService($pdo)))->handle(WebRequest::fromGlobals(), $_SESSION)->sendIfFinal();