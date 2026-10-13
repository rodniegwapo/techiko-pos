<?php

namespace App\Services\Ai\Actions;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use ReflectionMethod;
use ReflectionNamedType;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Runs a confirmed assistant change through the controller the normal page posts to, with the
 * same validation and business rules (product limit, unique SKU, one default store…), so the two
 * can never drift apart. The route's permission is checked by the caller before this runs.
 */
class ControllerCall
{
    /**
     * @param  string  $routeName  e.g. "domains.products.store"
     * @param  array<string, mixed>  $input  The form fields.
     * @param  array<string, mixed>  $parameters  Route parameters as models, e.g. ['domain' => $domain, 'product' => $product].
     */
    public function run(string $routeName, array $input, array $parameters, User $user, string $successMessage): ActionResult
    {
        $route = Route::getRoutes()->getByName($routeName);
        if (! $route) {
            return ActionResult::failed('This change is not available.');
        }

        $method = collect($route->methods())->first(fn ($m) => $m !== 'HEAD');
        $request = Request::create(route($routeName, $parameters), $method, $input);
        $request->headers->set('Accept', 'application/json');
        $request->setUserResolver(fn () => $user);
        if (app()->bound('session.store')) {
            $request->setLaravelSession(app('session.store'));
        }

        $route = clone $route;
        $route->bind($request);
        foreach ($parameters as $name => $value) {
            $route->setParameter($name, $value);
        }
        $request->setRouteResolver(fn () => $route);

        [$class, $action] = explode('@', $route->getActionName());

        // Code that reads request() during the call must see this request, not the confirm click.
        $previous = app('request');
        app()->instance('request', $request);

        try {
            $response = app($class)->{$action}(...$this->arguments($class, $action, $request, $parameters));
        } catch (ValidationException $e) {
            return ActionResult::failed(collect($e->errors())->flatten()->first() ?? 'Some details are not valid.', $e->errors());
        } catch (HttpExceptionInterface|AuthorizationException $e) {
            return ActionResult::failed($e->getMessage() ?: 'This change is not allowed.');
        } catch (Throwable $e) {
            report($e);

            return ActionResult::failed('Something went wrong while saving. Nothing was changed; please try again or use the page instead.');
        } finally {
            app()->instance('request', $previous);
        }

        if ($response instanceof Response && $response->getStatusCode() >= 400) {
            $message = $response instanceof JsonResponse ? ($response->getData(true)['message'] ?? null) : null;

            return ActionResult::failed($message ?: 'This change could not be saved.');
        }

        return ActionResult::done($successMessage);
    }

    /** The controller method's arguments: the request (a FormRequest validated like on a normal post), route models, then services. */
    private function arguments(string $class, string $action, Request $request, array $parameters): array
    {
        $arguments = [];
        foreach ((new ReflectionMethod($class, $action))->getParameters() as $parameter) {
            $type = $parameter->getType();
            $typeName = $type instanceof ReflectionNamedType && ! $type->isBuiltin() ? $type->getName() : null;

            if ($typeName && is_subclass_of($typeName, FormRequest::class)) {
                $arguments[] = $this->formRequest($typeName, $request);
            } elseif ($typeName && is_a($typeName, Request::class, true)) {
                $arguments[] = $request;
            } elseif (array_key_exists($parameter->getName(), $parameters)) {
                $arguments[] = $parameters[$parameter->getName()];
            } elseif ($typeName) {
                $arguments[] = app($typeName);
            } else {
                $arguments[] = $parameter->isDefaultValueAvailable() ? $parameter->getDefaultValue() : null;
            }
        }

        return $arguments;
    }

    /** @param  class-string<FormRequest>  $class */
    private function formRequest(string $class, Request $request): FormRequest
    {
        $form = $class::createFrom($request);
        $form->setContainer(app())->setRedirector(app('redirect'));
        $form->setUserResolver($request->getUserResolver());
        $form->setRouteResolver($request->getRouteResolver());
        $form->validateResolved();

        return $form;
    }
}
