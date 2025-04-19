<!DOCTYPE html>
<html dir="rtl" lang="he">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ config('app.name') }}</title>
    <style>
        body {
            font-family: system-ui, sans-serif;
            line-height: 1.6;
            color: #222;
            margin: 0;
            padding: 0;
            direction: rtl;
            background-color: #f5f5f5;
        }

        .container {
            max-width: 600px;
            margin: 20px auto;
            background-color: #ffffff;
            border-radius: 10px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            overflow: hidden;
            border: 1px solid #bbb;
        }

        .header {
            text-align: center;
            padding: 30px 20px;
            background-color: #F68589;
            color: white;
        }

        .header h1 {
            margin: 0;
            font-size: 36px;
            font-weight: 600;
            color: #fff;
        }

        .content {
            padding: 20px;
            background-color: #ffffff;
            direction: rtl;
            text-align: right;
            font-family: system-ui, sans-serif;
        }

        .content h2 {
            color: #79AE60;
            margin-top: 0;
            margin-bottom: 20px;
            font-size: 20px;
        }

        .info-box {
            border-radius: 8px;
            margin: 10px 0;
            background: #eee8;
            padding: 8px;
        }

        .info-box h3 {
            color: #222;
            margin-top: 0;
            margin-bottom: 2px;
            font-size: 16px;
        }

        .button {
            display: inline-block;
            padding: 7px 24px;
            background-color: #79AE60;
            text-decoration: none;
            border-radius: 6px;
            font-weight: 600;
            margin-bottom: 10px;
            text-align: center;
            transition: background-color 0.3s ease;
            color: #fff !important;
        }

        .button:hover {
            background-color: #A3D18D;
        }

        .button.secondary {
            background-color: #F68589;
        }

        .button.secondary:hover {
            background-color: #FFAEB1;
        }

        .footer {
            text-align: center;
            padding: 20px;
            color: #686666;
            font-size: 12px;
            background-color: #EEEEEE;
            border-top: 1px solid #ddd;
        }

        footer small {
            color: #222;
            font-weight: 600;
        }

        .warning {
            background-color: #FFAEB188;
            border-radius: 8px;
            padding: 8px;
            margin: 3px 0;
            font-weight: 600;
            color: #222;
        }

        .success {
            background-color: #A3D18D88;
            border-radius: 8px;
            padding: 8px;
            margin: 3px 0;
            color: #222;
            font-weight: 600;
        }

        .success p{
            margin-bottom: 0 !important;
        }

        .note {
            font-size: 12px;
            color: #686666;
            margin-top: 15px;
            padding: 10px;
            border-right: 3px solid #686666;
            background-color: #EEEEEE;
        }

        ul {
            list-style-position: inside;
            padding-right: 0;
        }

        li {
            margin-bottom: 8px;
        }

        strong {
            color: #222;
        }

        .text-center {
            text-align: center;
        }

        .button-container {
            text-align: center;
            margin: 25px 0;
        }

        a {
            text-decoration: none;
            color: #222 !important;
        }

        p {
            margin-top: 0 !important;
        }

        @media only screen and (max-width: 600px) {
            .container {
                margin: 0;
                border-radius: 0;
            }

            .content {
                padding: 20px;
            }
        }
    </style>
</head>

<body>
    <div class="container">
        <div class="header">
            <a href="{{ config("app.client_url") }}">
                <h1>{{ config('app.name') }}</h1>
            </a>
        </div>

        <div class="content">
            @yield('content')
        </div>

        <div class="footer">
            <p>© {{ date('Y') }} {{ config('app.name') }}. כל הזכויות שמורות.</p>
            <a href="{{ config("app.client_url") }}">
                <small>
                    קישור לעמוד הבית
                </small>
            </a>
        </div>
    </div>
</body>

</html>