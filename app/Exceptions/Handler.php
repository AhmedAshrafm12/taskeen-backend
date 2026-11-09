<?php

namespace App\Exceptions;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * The list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        $this->renderable(function (Throwable $e, $request) {
            if (! $request->is('api/*')) {
                return null;
            }
            if ($e instanceof ValidationException) {
                return response()->json(['message' => 'راجع البيانات المدخلة.', 'errors' => $e->errors()], 422);
            }
            if ($e instanceof UniqueConstraintViolationException) {
                return response()->json(['message' => 'هذا الطلب مسجل بالفعل. حدّث الصفحة لمراجعة النتيجة.'], 409);
            }
            $status = $e instanceof AuthenticationException ? 401
                : ($e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500);
            $message = match ($status) {
                401 => 'انتهت جلسة الدخول. سجل الدخول مرة أخرى.',
                403 => 'غير مسموح بتنفيذ هذا الإجراء.',
                404 => 'البيانات المطلوبة غير موجودة.',
                409 => 'تعارض في الطلب. حدّث البيانات وراجع السجلات قبل المحاولة مرة أخرى.',
                429 => 'محاولات كثيرة. انتظر دقيقة ثم حاول مرة أخرى.',
                default => 'تعذر تنفيذ الطلب. حاول مرة أخرى.',
            };

            return response()->json(['message' => $message], $status, $e instanceof HttpExceptionInterface ? $e->getHeaders() : []);
        });
        $this->reportable(function (Throwable $e) {
            //
        });
    }
}
