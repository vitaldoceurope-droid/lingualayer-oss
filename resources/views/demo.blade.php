<!DOCTYPE html>
<html lang="{{ config('lingua.source_language', 'es') }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>LinguaLayer — Demo</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: system-ui, -apple-system, sans-serif;
            background: #f5f5f7;
            color: #1d1d1f;
            min-height: 100vh;
            padding: 40px 20px;
        }
        .container { max-width: 680px; margin: 0 auto; }
        h1 { font-size: 2rem; font-weight: 700; margin-bottom: 8px; }
        .subtitle { color: #6e6e73; margin-bottom: 40px; font-size: 1.05rem; }
        .card {
            background: #fff;
            border-radius: 16px;
            padding: 32px;
            box-shadow: 0 1px 3px rgba(0,0,0,.08), 0 8px 24px rgba(0,0,0,.06);
            margin-bottom: 24px;
        }
        h2 { font-size: 1.2rem; margin-bottom: 20px; font-weight: 600; }
        label { display: block; font-size: 14px; font-weight: 500; margin-bottom: 6px; color: #3a3a3c; }
        input, textarea, select {
            width: 100%; padding: 10px 14px; border: 1px solid #d2d2d7;
            border-radius: 10px; font-size: 15px; outline: none;
            transition: border-color .2s;
            font-family: inherit;
        }
        input:focus, textarea:focus { border-color: #4f46e5; }
        textarea { resize: vertical; min-height: 80px; }
        .form-group { margin-bottom: 18px; }
        .btn {
            background: #4f46e5; color: #fff; border: none;
            border-radius: 10px; padding: 12px 28px;
            font-size: 15px; font-weight: 600; cursor: pointer;
            transition: background .2s;
            display: inline-flex; align-items: center; gap: 8px;
        }
        .btn:hover { background: #4338ca; }
        .result-box {
            background: #f5f5f7; border-radius: 10px; padding: 16px;
            font-size: 14px; color: #3a3a3c; min-height: 48px;
            margin-top: 16px; border: 1px solid #e5e5ea;
        }
        .badge {
            display: inline-block; background: #e0e7ff; color: #3730a3;
            border-radius: 999px; padding: 3px 10px; font-size: 12px;
            font-weight: 600; margin-bottom: 12px;
        }
        .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-top: 12px; }
        .info-item { background: #f5f5f7; border-radius: 10px; padding: 12px 16px; }
        .info-item .key { font-size: 11px; font-weight: 600; color: #6e6e73; text-transform: uppercase; letter-spacing: .5px; }
        .info-item .val { font-size: 15px; font-weight: 600; margin-top: 2px; }
        .notice {
            background: #fef3c7; border: 1px solid #fcd34d; border-radius: 10px;
            padding: 12px 16px; font-size: 13px; color: #92400e; margin-bottom: 24px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>LinguaLayer</h1>
        <p class="subtitle">Traducción bidireccional con IA para Laravel — sin tocar tu código.</p>

        <div class="notice">
            El selector de idioma aparece arriba a la derecha. Cambia el idioma y la página se recargará traducida.
        </div>

        {{-- Bloque de información del sistema --}}
        <div class="card">
            <span class="badge">Estado del sistema</span>
            <h2>Configuración activa</h2>
            <div class="info-grid">
                <div class="info-item">
                    <div class="key">Idioma base</div>
                    <div class="val">{{ strtoupper(config('lingua.source_language')) }}</div>
                </div>
                <div class="info-item">
                    <div class="key">Idioma actual</div>
                    <div class="val">{{ strtoupper(request()->cookie('lingua_lang', config('lingua.source_language'))) }}</div>
                </div>
                <div class="info-item">
                    <div class="key">Caché</div>
                    <div class="val">{{ ucfirst(config('lingua.cache_driver')) }}</div>
                </div>
                <div class="info-item">
                    <div class="key">Modelo IA</div>
                    <div class="val">{{ config('lingua.gemini_model') }}</div>
                </div>
            </div>
        </div>

        {{-- Formulario de prueba --}}
        <div class="card">
            <span class="badge">Prueba de entrada</span>
            <h2>Formulario de ejemplo</h2>
            <p style="font-size:14px;color:#6e6e73;margin-bottom:20px;">
                Escribe en tu idioma. Al enviar, LinguaLayer traduce los campos al idioma base del sistema antes de que lleguen al servidor.
            </p>

            <form action="/lingua/demo" method="POST">
                @csrf
                <div class="form-group">
                    <label for="nombre">Nombre completo</label>
                    <input type="text" id="nombre" name="nombre" placeholder="Tu nombre completo" value="{{ old('nombre') }}">
                </div>
                <div class="form-group">
                    <label for="email">Correo electrónico</label>
                    <input type="email" id="email" name="email" placeholder="correo@ejemplo.com" value="{{ old('email') }}">
                </div>
                <div class="form-group">
                    <label for="motivo">Motivo de consulta</label>
                    <textarea id="motivo" name="motivo" placeholder="Describe el motivo de tu consulta médica">{{ old('motivo') }}</textarea>
                </div>
                <div class="form-group">
                    <label for="sintomas">Síntomas principales</label>
                    <input type="text" id="sintomas" name="sintomas" placeholder="Dolor de cabeza, fiebre, tos..." value="{{ old('sintomas') }}">
                </div>

                <button type="submit" class="btn">
                    Enviar consulta
                </button>
            </form>

            @if(session('submitted'))
                <div class="result-box">
                    <strong>Datos recibidos por el servidor (en idioma base):</strong><br><br>
                    @foreach(session('submitted') as $key => $value)
                        <div><strong>{{ $key }}:</strong> {{ $value }}</div>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- Bloque de contenido estático para ver traducción de salida --}}
        <div class="card">
            <span class="badge">Prueba de salida</span>
            <h2>Contenido visible traducido automáticamente</h2>
            <p>
                Este párrafo está escrito en español. Cuando cambias el idioma con el selector,
                LinguaLayer intercepta esta respuesta HTML y traduce todos los textos visibles
                usando la API de Gemini. Los atributos CSS, las URLs y el código JavaScript
                no se modifican nunca.
            </p>
            <br>
            <p>
                Beneficios principales del sistema: sin tocar controladores, sin modificar vistas existentes,
                sin alterar la base de datos. La traducción funciona de forma transparente en cualquier
                aplicación Laravel existente.
            </p>
            <ul style="margin-top:16px;margin-left:20px;line-height:2">
                <li>Traducción completa de HTML visible</li>
                <li>Caché automático para reducir llamadas a la API</li>
                <li>Selector de idiomas con banderas emoji</li>
                <li>Interceptor de formularios antes del submit</li>
                <li>Detección automática del idioma del navegador</li>
            </ul>
        </div>
    </div>
</body>
</html>
