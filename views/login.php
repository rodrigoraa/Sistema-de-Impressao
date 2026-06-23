<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/png" href="/favicon.ico?v=2">
    <link rel="apple-touch-icon" href="/image/pwa-icon-180.png">
    <link rel="manifest" href="/manifest.webmanifest">
    <meta name="theme-color" content="#0f3f4f">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="Sistema de Impressão">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <title>Sistema de Impressão | Acesso</title>

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap"
        rel="stylesheet">

    <link rel="stylesheet" href="/css/login.css?v=20260511">
</head>

<body>

    <main class="login-shell">

        <!-- LADO ESQUERDO -->
        <section class="login-hero">
            <span class="hero-badge">Sistema institucional</span>

            <h1>Impressão EESJ</h1>

            <p>
                Professores acessem com seu CPF.
            </p>

            <div class="hero-points">
                <div><i class="bi bi-printer"></i><span>Envio rápido de impressões</span></div>
                <div><i class="bi bi-shield-check"></i><span>Controle administrativo</span></div>
                <div><i class="bi bi-clock-history"></i><span>Histórico de uso</span></div>
            </div>
        </section>

        <!-- LADO DIREITO -->
        <section class="login-panel">

            <!-- LOGO -->
            <div class="panel-brand">
                <div class="brand-mark">
                    <img src="/image/logo_escola.png" class="brand-logo">
                </div>
                <div>
                    <strong>Sistema de Impressão</strong>
                    <span>Acesso por CPF</span>
                </div>
            </div>

            <div class="panel-copy">
                <h2>Entrar no sistema</h2>
                <p>Informe seu CPF. Administradores devem informar senha.</p>
            </div>

            <!-- ALERT -->
            <?php if (!empty($_SESSION['flash'])): ?>
                <div class="alert alert-danger d-flex align-items-center gap-2">
                    <i class="bi bi-exclamation-circle-fill"></i>
                    <span><?= htmlspecialchars($_SESSION['flash']);
                    unset($_SESSION['flash']); ?></span>
                </div>
            <?php endif; ?>

            <!-- FORM -->
            <form method="post" class="login-form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">

                <div class="mb-3">
                    <label class="form-label">CPF</label>
                    <input type="text" name="matricula" id="matricula" class="form-control form-control-lg" required
                        autocomplete="username">
                </div>

                <div class="mb-3" id="senha-wrap" style="display:none;">
                    <label class="form-label">Senha</label>
                    <input type="password" name="senha" id="senha" class="form-control form-control-lg"
                        autocomplete="current-password">
                </div>

                <button class="btn btn-primary btn-lg w-100">
                    Entrar
                </button>

            </form>

            <div class="panel-footer">
                <small>Cadastro de usuários apenas pelo administrador.</small>
            </div>

            <div class="login-assistance">
                <div>
                    <i class="bi bi-person-badge"></i>
                    <span>Professor entra apenas com CPF</span>
                </div>
                <div>
                    <i class="bi bi-shield-lock"></i>
                    <span>Administrador usa CPF + senha</span>
                </div>
            </div>

        </section>

    </main>

    <script>
        const admins = <?= json_encode($admin_matriculas ?? []) ?>;
        const input = document.getElementById('matricula');
        const senhaWrap = document.getElementById('senha-wrap');

        input.addEventListener('input', () => {
            const val = input.value.replace(/\D/g, '');
            senhaWrap.style.display = admins.includes(val) ? 'block' : 'none';
        });
    </script>
    <script src="/js/pwa.js?v=20260623"></script>

</body>

</html>
