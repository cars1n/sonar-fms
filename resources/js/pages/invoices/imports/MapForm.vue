<template>
  <form class="xl:col-span-3" @submit.prevent="saveImport">
    <Alert v-if="form.hasErrors" level="error" class="mb-8">
      {{ __('Please correct the errors below and try again.') }}
    </Alert>

    <FormMultipartWrapper>
      <!-- Student mapping settings -->
      <div>
        <div class="mb-6">
          <CardSectionHeader>{{ __('Student identity settings') }}</CardSectionHeader>
          <HelpText>
            {{ __('Configure how students are found and student import behavior.') }}
          </HelpText>
        </div>
        <Fieldset>
          <InputWrap :error="form.errors.student_attribute">
            <Label for="title" :required="true">{{ __('Student identity field') }}</Label>
            <Select v-model="form.student_attribute" id="student_attribute">
              <option :value="null" disabled selected>{{ __('Select a student field') }}</option>
              <option value="sis_id">{{ __('SIS ID (DCID)') }}</option>
              <option value="student_number">{{ __('Student number') }}</option>
              <option value="email">{{ __('Email') }}</option>
            </Select>
            <HelpText>
              {{ __('Select the field by which a student can be uniquely identified.') }}
            </HelpText>
          </InputWrap>

          <InputWrap :error="form.errors.student_column">
            <Label for="title" :required="true">{{ __('Student reference column') }}</Label>
            <ColumnSelector v-model="form.student_column" id="student_column" :headers="headers" :required="true" />
            <HelpText>
              {{ __('Select the column that holds student identifying data.') }}
            </HelpText>
          </InputWrap>

          <!-- Not sure if we want this behavior -->
<!--          <InputWrap>-->
<!--            <CheckboxWrapper>-->
<!--              <Checkbox v-model:checked="form.create_new_students" />-->
<!--              <CheckboxText>{{ __('Create new students has blank identifying value') }}</CheckboxText>-->
<!--            </CheckboxWrapper>-->
<!--            <HelpText>-->
<!--              {{ __("Enabling this option will attempt to create a new student record if a row's value is empty for the student reference column.") }}-->
<!--            </HelpText>-->
<!--          </InputWrap>-->
        </Fieldset>
      </div>

      <!-- Invoice details -->
      <div class="pt-8">
        <div class="mb-6">
          <CardSectionHeader>{{ __('Invoice details') }}</CardSectionHeader>
          <HelpText>
            {{ __('These are the general details about the invoice.') }}
          </HelpText>
        </div>
        <Fieldset>
          <InputWrap :error="form.errors.title">
            <Label for="title" :required="true">{{ __('Title') }}</Label>
            <MapField v-model="form.title" :headers="headers" id="title">
              <TemplateBuilder v-model="form.title.value" id="title" :placeholder="__('Invoice title')" />
              <template v-slot:after>
                <HelpText>
                  {{ __('Give the invoice a meaningful title that is easily recognizable and descriptive.') }}
                </HelpText>
              </template>
            </MapField>
          </InputWrap>

          <InputWrap>
            <Label for="description">{{ __('Description') }}</Label>
            <MapField v-model="form.description" :headers="headers" id="description">
              <Textarea v-model="form.description.value" id="description" />
              <template v-slot:after>
                <HelpText>
                  {{ __('This is a description of the invoice that will be displayed with the invoice.') }}
                </HelpText>
              </template>
            </MapField>
          </InputWrap>

          <InputWrap :error="form.errors.invoice_date">
            <Label for="invoice_date">{{ __('Invoice date') }}</Label>
            <MapField v-model="form.invoice_date" :headers="headers" id="invoice_date">
              <DatePicker v-model="form.invoice_date.value" id="invoice_date" mode="date" />
              <template v-slot:after>
                <HelpText>
                  {{ __('This is the date that will appear on the invoice as the date the invoice was issued.') }}
                </HelpText>
              </template>
            </MapField>
          </InputWrap>

          <InputWrap :error="form.errors.available_at">
            <Label for="available_at">{{ __('Availability') }}</Label>
            <MapField v-model="form.available_at" :headers="headers" id="available_at">
              <DatePicker v-model="form.available_at.value" id="available_at" />
              <template v-slot:after>
                <HelpText>
                  {{ __("Set a date and time that this invoice is available to the student's guardians or other contacts. Before the configured time, it will only be viewable to admins. This is helpful to use if you want to prepare and preview invoices before actually making them available for the student. The time is based on your current timezone of :timezone. If this timezone is incorrect you can change it in your Personal Settings.", { timezone }) }}
                </HelpText>
              </template>
            </MapField>
          </InputWrap>

          <InputWrap :error="form.errors.due_at">
            <Label for="due_at">{{ __('Due date') }}</Label>
            <MapField v-model="form.due_at" :headers="headers" id="due_at">
              <DatePicker v-model="form.due_at.value" id="due_at" />
              <template v-slot:after>
                <HelpText>
                  {{ __("Set the date and time that this invoice is due, or don't set one to not have a due date. The time is based on your current timezone of :timezone. If this timezone is incorrect you can change it in your Personal Settings.", { timezone }) }}
                </HelpText>
              </template>
            </MapField>
          </InputWrap>

          <InputWrap>
            <Label for="term_id">{{ __('Term') }}</Label>
            <MapField v-model="form.term_id" :headers="headers" id="term_id">
              <Select v-model="form.term_id.value" id="term_id">
                <option :value="null">{{ __('No term') }}</option>
                <option
                  v-for="term in terms"
                  :key="term.id"
                  :value="term.id"
                >
                  {{ term.school_years }} - {{ term.name }}
                </option>
              </Select>
              <template v-slot:after>
                <HelpText>
                  {{ __('Associating a term with an invoice allows you to group invoices by school term and offers another reporting perspective.') }}
                </HelpText>
              </template>
            </MapField>
          </InputWrap>

          <InputWrap :error="form.errors.grade_level_adjustment">
            <Label for="grade_level_adjustment" :required="true">{{ __('Grade level adjustment') }}</Label>
            <MapField v-model="form.grade_level_adjustment" :headers="headers" id="grade_level_adjustment">
              <Input v-model="form.grade_level_adjustment.value" id="grade_level_adjustment" type="number" />
              <template v-slot:after>
                <HelpText>{{ __("This will adjust how the student's grade level appears on the generated invoice by year. For example, putting 1 will display a grade 6 student as grade 7. Negative numbers will reduce the grade level.") }}</HelpText>
              </template>
            </MapField>
          </InputWrap>

          <InputWrap>
            <CheckboxWrapper>
              <Checkbox v-model:checked="form.notify" />
              <CheckboxText>{{ __('Queue notification') }}</CheckboxText>
            </CheckboxWrapper>
            <HelpText>
              {{ __("Having this option enabled will automatically queue an email to be sent notifying the appropriate parties of the available invoice. There is a 15-minute delay of sending the notification which allows you to make adjustments, cancel the notification, or delete the invoice all together. If this is not enabled, you may send a notification manually later.") }}
            </HelpText>
          </InputWrap>
        </Fieldset>
      </div>

      <!-- Invoice line items -->
      <div class="pt-8">
        <InvoiceItemMapping
          v-model="form.items"
          :form="form"
          :headers="headers"
        />
      </div>

      <!-- Scholarships -->
      <div class="pt-8">
        <InvoiceScholarshipMapping
          v-model="form.scholarships"
          :form="form"
          :headers="headers"
        />
      </div>

      <!-- Payment schedules -->
      <div class="pt-8">
        <InvoicePaymentScheduleMapping
          v-model="form.payment_schedules"
          :form="form"
          :headers="headers"
        />
      </div>

      <!-- Tax -->
      <div class="pt-8" v-if="school.collect_tax">
        <InvoiceTaxMapping
          v-model="form"
          :headers="headers"
        />
      </div>
    </FormMultipartWrapper>

    <div class="mt-8 p-4 border-t border-gray-400 bg-white dark:bg-gray-700 dark:border-gray-200 rounded-b-md">
      <Button type="submit" :loading="form.processing">
        {{ __('Save mapping') }}
      </Button>
    </div>
  </form>

</template>

<script>
import { computed, inject, ref, watch } from 'vue'
import { useForm, usePage } from '@inertiajs/vue3'
import { PlusSmIcon } from '@heroicons/vue/solid'
import { TrashIcon, LinkIcon } from '@heroicons/vue/outline'
import Fieldset from '@/components/forms/Fieldset.vue'
import HelpText from '@/components/HelpText.vue'
import InputWrap from '@/components/forms/InputWrap.vue'
import Checkbox from '@/components/forms/Checkbox.vue'
import Label from '@/components/forms/Label.vue'
import CheckboxText from '@/components/forms/CheckboxText.vue'
import CheckboxWrapper from '@/components/forms/CheckboxWrapper.vue'
import fetchesTerms from '@/composition/fetchesTerms.js'
import Select from '@/components/forms/Select.vue'
import Textarea from '@/components/forms/Textarea.vue'
import displaysCurrency from '@/composition/displaysCurrency.js'
import Input from '@/components/forms/Input.vue'
import Button from '@/components/Button.vue'
import FormMultipartWrapper from '@/components/forms/FormMultipartWrapper.vue'
import CardSectionHeader from '@/components/CardSectionHeader.vue'
import FadeIn from '@/components/transitions/FadeIn.vue'
import DatePicker from '@/components/forms/DatePicker.vue'
import Alert from '@/components/Alert.vue'
import displaysDate from '@/composition/displaysDate.js'
import CardWrapper from '@/components/CardWrapper.vue'
import CardPadding from '@/components/CardPadding.vue'
import InvoiceSummary from '@/components/InvoiceSummary.vue'
import Modal from '@/components/Modal.vue'
import invoiceImportMapField from '@/composition/invoiceImportMapField.js'
import ColumnSelector from '@/components/forms/ColumnSelector.vue'
import MapField from '@/components/forms/MapField.vue'
import FadeInGroup from '@/components/transitions/FadeInGroup.vue'
import isUndefined from 'lodash/isUndefined'
import InvoiceItemMapping from '@/components/forms/invoices/InvoiceItemMapping.vue'
import InvoiceScholarshipMapping from '@/components/forms/invoices/InvoiceScholarshipMapping.vue'
import InvoicePaymentScheduleMapping from '@/components/forms/invoices/InvoicePaymentScheduleMapping.vue'
import InvoiceTaxMapping from '@/components/forms/invoices/InvoiceTaxMapping.vue'
import TemplateBuilder from '@/components/forms/TemplateBuilder.vue'
import useSchool from '@/composition/useSchool.js'

export default {
  components: {
    TemplateBuilder,
    InvoiceTaxMapping,
    InvoicePaymentScheduleMapping,
    InvoiceScholarshipMapping,
    InvoiceItemMapping,
    FadeInGroup,
    MapField,
    ColumnSelector,
    Modal,
    InvoiceSummary,
    CardPadding,
    CardWrapper,
    Alert,
    FadeIn,
    CardSectionHeader,
    FormMultipartWrapper,
    Button,
    Input,
    Textarea,
    Select,
    CheckboxWrapper,
    CheckboxText,
    Checkbox,
    InputWrap,
    HelpText,
    Fieldset,
    Label,
    PlusSmIcon,
    TrashIcon,
    LinkIcon,
    DatePicker,
  },
  props: {
    invoiceTemplate: {
      type: Object,
      default: () => ({})
    },
    invoiceForm: {
      type: Object,
      default: () => ({})
    },
    headers: {
      type: Array,
      required: true,
    },
    invoiceImport: {
      type: Object,
      default: () => ({})
    },
    errors: {
      type: Object,
      default: () => ({})
    }
  },
  emits: ['update:invoiceForm'],

  setup (props, { emit }) {
    const $route = inject('$route')
    const { terms } = fetchesTerms()
    const reviewing = ref(false)
    const { addMapFieldValue } = invoiceImportMapField()
    const page = usePage()
    const form = useForm({
      student_attribute: props.invoiceImport.mapping?.student_attribute || null,
      student_column: props.invoiceImport.mapping?.student_column || null,
      create_new_students: props.invoiceImport.mapping?.create_new_students || false,
      title: props.invoiceImport.mapping?.title || addMapFieldValue(),
      description: props.invoiceImport.mapping?.description || addMapFieldValue(),
      term_id: props.invoiceImport.mapping?.term_id || addMapFieldValue(),
      invoice_date: props.invoiceImport.mapping?.invoice_date || addMapFieldValue(new Date),
      available_at: props.invoiceImport.mapping?.available_at || addMapFieldValue(),
      due_at: props.invoiceImport.mapping?.due_at || addMapFieldValue(),
      grade_level_adjustment: props.invoiceImport.mapping?.grade_level_adjustment || addMapFieldValue(0),
      notify: props.invoiceImport.mapping?.notify ?? true,
      items: props.invoiceImport.mapping?.items || [],
      scholarships: props.invoiceImport.mapping?.scholarships || [],
      payment_schedules: props.invoiceImport.mapping?.payment_schedules || [],
      apply_tax: isUndefined(props.invoiceImport.mapping?.apply_tax) ? true : props.invoiceImport.mapping.apply_tax,
      use_school_tax_defaults: isUndefined(props.invoiceImport.mapping?.use_school_tax_defaults) ? true : props.invoiceImport.mapping.use_school_tax_defaults,
      tax_rate: props.invoiceImport.mapping?.tax_rate || addMapFieldValue(),
      tax_label: props.invoiceImport.mapping?.tax_label || addMapFieldValue(),
      apply_tax_to_all_items: props.invoiceImport.mapping?.apply_tax_to_all_items || true,
      tax_items: props.invoiceImport.mapping?.tax_items || [],
    })
    form.errors = props.errors
    // Emit the initial value
    emit('update:invoiceForm', form)

    const { school } = useSchool()

    const { timezone, displayDate } = displaysDate()
    const { displayCurrency } = displaysCurrency()

    const saveImport = () => {
      form.put($route('invoices.imports.map', props.invoiceImport))
    }

    // Watch for changes to apply a template
    watch(() => props.invoiceTemplate, (state) => {
      Object.keys(form.data())
        .forEach(field => {
          if (typeof state[field] !== 'undefined') {
            form[field] = state[field]
          }
        })
    })

    watch(() => form, (state) => {
      emit('update:invoiceForm', state)
    }, { deep: true })

    return {
      reviewing,
      school,
      terms,
      form,
      saveImport,
      displayCurrency,
      displayDate,
      timezone,
    }
  },
}
</script>
