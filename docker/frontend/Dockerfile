FROM node:24.22.1-bookworm-slim@sha256:be8204d4bd327363357f532187b752ff62759c31bedf98e9e18fc08c9cbd605c AS tooling
WORKDIR /app
ENV NG_CLI_ANALYTICS=false
COPY frontend/package.json frontend/package-lock.json ./
RUN --mount=type=cache,target=/root/.npm npm ci --no-audit --no-fund
COPY frontend/ ./
CMD ["npm", "test"]

FROM tooling AS build
RUN npm run build

FROM node:24.22.1-bookworm-slim@sha256:be8204d4bd327363357f532187b752ff62759c31bedf98e9e18fc08c9cbd605c AS api-docs
WORKDIR /docs
COPY api-docs/package.json api-docs/package-lock.json ./
RUN --mount=type=cache,target=/root/.npm npm ci --ignore-scripts --no-audit --no-fund

FROM nginx:1.30.6-alpine@sha256:0e52bd054126cdc040b1276c0af347aeb02a41b9cfe305d6094767ac30e97949 AS production
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY --from=build /app/dist/frontend/browser /usr/share/nginx/html
COPY --from=api-docs /docs/node_modules/swagger-ui-dist/swagger-ui-bundle.js /usr/share/nginx/html/docs/swagger-ui-bundle.js
COPY --from=api-docs /docs/node_modules/swagger-ui-dist/swagger-ui.css /usr/share/nginx/html/docs/swagger-ui.css
COPY --from=api-docs /docs/node_modules/swagger-ui-dist/LICENSE /usr/share/nginx/html/docs/LICENSE
COPY --from=api-docs /docs/node_modules/swagger-ui-dist/swagger-ui-bundle.js.LICENSE.txt /usr/share/nginx/html/docs/swagger-ui-bundle.js.LICENSE.txt
COPY api-docs/index.html api-docs/swagger-initializer.js /usr/share/nginx/html/docs/
COPY docs/openapi.json /usr/share/nginx/html/docs/openapi.json
EXPOSE 80
