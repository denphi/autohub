{{/* Common name/label helpers */}}
{{- define "autohub.name" -}}{{ .Release.Name }}{{- end -}}

{{- define "autohub.labels" -}}
app.kubernetes.io/name: autohub
app.kubernetes.io/instance: {{ .Release.Name }}
app.kubernetes.io/managed-by: {{ .Release.Service }}
helm.sh/chart: autohub-{{ .Chart.Version }}
{{- end -}}

{{- define "autohub.secretName" -}}
{{- if .Values.secret.existingSecret }}{{ .Values.secret.existingSecret }}{{ else }}{{ .Release.Name }}-secret{{ end -}}
{{- end -}}

{{/*
Shared container env: the non-secret map, then secret keys via secretKeyRef, then
service-discovery defaults for DB_HOST/HUB_SMTP_HOST when left blank.
*/}}
{{- define "autohub.env" -}}
{{- range $k, $v := .Values.env }}
{{- if not (or (eq $k "DB_HOST") (eq $k "HUB_SMTP_HOST")) }}
- name: {{ $k }}
  value: {{ $v | quote }}
{{- end }}
{{- end }}
{{- /* emit these once: user override, else in-cluster service name */}}
- name: DB_HOST
  value: {{ .Values.env.DB_HOST | default (printf "%s-db" .Release.Name) | quote }}
- name: HUB_SMTP_HOST
  value: {{ .Values.env.HUB_SMTP_HOST | default (printf "%s-mail" .Release.Name) | quote }}
{{- range $k := (list "DB_PASSWORD" "DB_ROOT_PASSWORD" "HUB_SECRET" "HUB_ADMIN_PASSWORD" "HUB_SMTP_PASSWORD") }}
- name: {{ $k }}
  valueFrom:
    secretKeyRef:
      name: {{ include "autohub.secretName" $ }}
      key: {{ $k }}
{{- end }}
{{- end -}}
