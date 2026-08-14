import { Button } from '@/components/ui/button';
import {
   Dialog,
   DialogContent,
   DialogDescription,
   DialogFooter,
   DialogHeader,
   DialogTitle,
   DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useForm, usePage } from '@inertiajs/react';
import { Download, Upload, AlertCircle, FileSpreadsheet } from 'lucide-react';
import { useState, useEffect } from 'react';

interface Props {
   exam: Exam;
   handler: React.ReactNode;
}

const ImportQuestionsDialog = ({ exam, handler }: Props) => {
   const [open, setOpen] = useState(false);
   const { errors } = usePage().props;
   
   // Cast errors safely
   const importErrors = errors?.import_errors as string[] | undefined;

   const { data, setData, post, processing, reset, clearErrors } = useForm({
      file: null as File | null,
   });

   // Close and reset dialog when successful
   useEffect(() => {
      if (open) {
         // Clear errors on open
         clearErrors();
      }
   }, [open]);

   const handleFileChange = (e: React.ChangeEvent<HTMLInputElement>) => {
      if (e.target.files && e.target.files[0]) {
         setData('file', e.target.files[0]);
      }
   };

   const handleSubmit = (e: React.FormEvent) => {
      e.preventDefault();
      if (!data.file) return;

      post(`/dashboard/exams/${exam.id}/questions/import`, {
         preserveScroll: true,
         onSuccess: () => {
            reset();
            setOpen(false);
         },
      });
   };

   return (
      <Dialog open={open} onOpenChange={setOpen}>
         <DialogTrigger asChild>{handler}</DialogTrigger>
         <DialogContent className="sm:max-w-[500px]">
            <form onSubmit={handleSubmit}>
               <DialogHeader>
                  <DialogTitle className="flex items-center gap-2">
                     <FileSpreadsheet className="h-5 w-5 text-green-600" />
                     Import Exam Questions
                  </DialogTitle>
                  <DialogDescription>
                     Upload a CSV file to add questions in bulk. Supported types include Multiple Choice, Multiple Select, True/False, and Short Answer.
                  </DialogDescription>
               </DialogHeader>

               <div className="my-6 space-y-4">
                  {/* Download Template Alert */}
                  <div className="flex items-start gap-3 rounded-lg border border-blue-100 bg-blue-50/50 p-4 text-sm text-blue-800">
                     <Download className="mt-0.5 h-4 w-4 shrink-0 text-blue-600" />
                     <div>
                        <span className="font-medium">Get the import template</span>
                        <p className="mt-1 text-xs text-blue-700">
                           Ensure your CSV matches our official column structure. Microsoft Excel and Google Sheets can edit this file natively.
                        </p>
                        <a
                           href="/dashboard/exam-questions/import/sample"
                           className="mt-2 inline-flex items-center gap-1.5 font-semibold text-blue-600 hover:text-blue-800"
                        >
                           Download Sample CSV Template
                        </a>
                     </div>
                  </div>

                  {/* Validation Error Reports */}
                  {importErrors && importErrors.length > 0 && (
                     <div className="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800">
                        <div className="flex items-center gap-2 font-medium">
                           <AlertCircle className="h-4 w-4 text-red-600" />
                           Import failed with errors:
                        </div>
                        <ul className="mt-2 max-h-36 list-inside list-disc overflow-y-auto space-y-1 text-xs">
                           {importErrors.map((error, idx) => (
                              <li key={idx} className="break-all">{error}</li>
                           ))}
                        </ul>
                     </div>
                  )}

                  {/* File Upload Input */}
                  <div className="space-y-2">
                     <Label htmlFor="csv-file">Select CSV File</Label>
                     <Input
                        id="csv-file"
                        type="file"
                        accept=".csv,text/csv,text/comma-separated-values"
                        onChange={handleFileChange}
                        className="cursor-pointer"
                        required
                     />
                  </div>
               </div>

               <DialogFooter>
                  <Button
                     type="button"
                     variant="outline"
                     onClick={() => setOpen(false)}
                     disabled={processing}
                  >
                     Cancel
                  </Button>
                  <Button type="submit" disabled={processing || !data.file}>
                     <Upload className="mr-2 h-4 w-4" />
                     {processing ? 'Importing...' : 'Upload & Import'}
                  </Button>
               </DialogFooter>
            </form>
         </DialogContent>
      </Dialog>
   );
};

export default ImportQuestionsDialog;
