window.onload = () => {
  window.ui = SwaggerUIBundle({
    url: './openapi.json',
    dom_id: '#swagger-ui',
    deepLinking: true,
    displayOperationId: true,
    persistAuthorization: false,
    queryConfigEnabled: false,
    validatorUrl: null,
    presets: [SwaggerUIBundle.presets.apis],
  });
};
