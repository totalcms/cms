<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Total CMS API Specification</title>
    <link rel="stylesheet" href="../tcms-assets/swagger-ui/swagger-ui.css" />
</head>
<body>
<div id="swagger-ui"></div>
<script src="../tcms-assets/swagger-ui/swagger-ui-standalone-preset.js"></script>
<script src="../tcms-assets/swagger-ui/swagger-ui-bundle.js"></script>
<script>
    window.onload = function () {
        const ui = SwaggerUIBundle({
            spec: <?php echo $spec; ?>,
            dom_id: '#swagger-ui',
            deepLinking: true,
            supportedSubmitMethods: [],
            presets: [
                SwaggerUIBundle.presets.apis,
            ],
            plugins: [
                SwaggerUIBundle.plugins.DownloadUrl,
                function() {
                    return {
                        statePlugins: {
                            spec: {
                                wrapSelectors: {
                                    allowTryItOutFor: () => () => false
                                }
                            }
                        }
                    }
                }
            ],
        })
        window.ui = ui
    }
</script>
</body>
</html>
